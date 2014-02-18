<?php

class CRM_Reports_Form_Report_DoubleGroupMembershipReport extends CRM_Report_Form {

  protected $_addressField = FALSE;
  protected $_emailField = FALSE;
  protected $_summary = NULL;
  protected $_customGroupGroupBy = FALSE;
  protected $_add2groupSupported = FALSE;
  protected $group_ids = array();
  protected $_exposeContactID = FALSE;

  function __construct() {

    /*
     * retrieve all groups that have children
     * if there is a group called Donors, set to default
     * (specific to MAF Norge)
     */
    $group_params = array(
      'options' => array('limit' => 99999)
    );
    $groups_api = civicrm_api3('Group', 'Get', $group_params);
    $group_list = array();
    $group_default = 0;
    foreach ($groups_api['values'] as $group_id => $group_api) {
      if (!empty($group_api['children'])) {
        $group_list[$group_id] = $group_api['title'];
      }
      if ($group_api['title'] == "Donors") {
        $group_default = $group_id;
      }
    }

    $this->_columns = array(
      'civicrm_contact' => array(
        'dao' => 'CRM_Contact_DAO_Contact',
        'filters' => array(
          'parent' => array(
            'title' => ts('Parent Group'),
            'operatorType' => CRM_Report_Form::OP_SELECT,
            'required' => TRUE,
            'default' => $group_default,
            'options' => $group_list
          ),
				),
      ),
    );
    $this->_groupFilter = FALSE;
    $this->_tagFilter = FALSE;
    parent::__construct();

    $donor_groups = CRM_Contact_BAO_Group::getGroups(array('title' => 'Donors'));
    $donorGroupIds = array();
    foreach ($donor_groups as $group) {
      $donorGroupIds[] = $group->id;
    }
    if (count($donorGroupIds)) {
      $this->group_ids = CRM_Contact_BAO_GroupNesting::getDescendentGroupIds($donorGroupIds, false);
    }
  }

  function preProcess() {
    $this->assign('reportTitle', ts('Multiple group membership at this moment'));
    parent::preProcess();
  }

  function postProcess() {

    $this->beginPostProcess();


    /*
     * retrieve children from selected parents
     */
    $parent_group_params = array('id' => $this->_submitValues['parent_value']);
    //$parent_group = civicrm_api3('Group', 'Getsingle', $parent_group_params);
    $parent_group['children'] = implode(',', CRM_Contact_BAO_GroupNesting::getDescendentGroupIds(array($parent_group_params['id']), false));



    $this->_columnHeaders = array(
      'contact_id' => array('title' => ts('Id')),
      'display_name' => array('title' => ts('Contact')),
      'count' => array('title' => ts('Total groups')),
    );

		$date = date('Y-m-d H:i:s');

    $sql = "SELECT 
        `c`.`id` AS `contact_id`,  
        `c`.`display_name` AS `display_name`,
        count(`c`.`id`) AS `count`
        FROM `civicrm_contact` `c`
        INNER JOIN `civicrm_group_contact` `gc` ON `c`.`id` = `gc`.`contact_id` 
        LEFT JOIN `civicrm_subscription_history` `csh_added` ON 
          `c`.`id` = `csh_added`.`contact_id` AND 
          `gc`.`group_id` = `csh_added`.`group_id` AND 
          `csh_added`.`status` = 'Added' 
        LEFT JOIN `civicrm_subscription_history` `csh_removed` ON 
          `c`.`id` = `csh_removed`.`contact_id` AND 
          `gc`.`group_id` = `csh_removed`.`group_id` AND 
          `csh_removed`.`status` = 'Removed' 
        LEFT JOIN `civicrm_group` `g` ON `gc`.`group_id` = `g`.`id`
        WHERE
          `g`.`id` IN (" . $parent_group['children'] . ") 
          AND (`csh_added`.`date` IS NULL OR `csh_added`.`date` <= '".$date."') 
          AND (`csh_removed`.`date` IS NULL OR `csh_removed`.`date` >= '".$date."')
        GROUP BY `c`.`id`
        HAVING count(`c`.`id`) > 1
        ORDER BY `c`.`display_name`";

    $rows = array();
    $this->buildRows($sql, $rows);

    $this->formatDisplay($rows);
    $this->doTemplateAssignment($rows);
    $this->endPostProcess($rows);
  }

  function alterDisplay(&$rows) {
    // custom code to alter rows
    $entryFound = false;
    foreach ($rows as $rowNum => $row) {
      if (array_key_exists('display_name', $row)) {
        $url = CRM_Utils_System::url("civicrm/contact/view", 'reset=1&cid=' . $row['contact_id'], $this->_absoluteUrl);
        $rows[$rowNum]['display_name_link'] = $url;
        $rows[$rowNum]['display_name_hover'] = ts("View Contact details for this contact.");
        $entryFound = true;
      }
      if (array_key_exists('contact_id', $row)) {
        $url = CRM_Utils_System::url("civicrm/contact/view", 'reset=1&cid=' . $row['contact_id'], $this->_absoluteUrl);
        $rows[$rowNum]['contact_id_link'] = $url;
        $rows[$rowNum]['contact_id_hover'] = ts("View Contact details for this contact.");
        $entryFound = true;
      }
      // skip looking further in rows, if first row itself doesn't
      // have the column we need
      if (!$entryFound) {
        break;
      }
    }
  }

  function addFilters() {
    require_once 'CRM/Utils/Date.php';
    require_once 'CRM/Core/Form/Date.php';
    $count = 1;
    foreach ($this->_filters as $table => $attributes) {
      foreach ($attributes as $fieldName => $field) {
        // get ready with option value pair
        $operations = $this->getOperationPair(CRM_Utils_Array::value('operatorType', $field), $fieldName);

        $filters[$table][$fieldName] = $field;

        switch (CRM_Utils_Array::value('operatorType', $field)) {
          case CRM_Report_FORM::OP_SELECT :
            // assume a select field
            $this->addElement('select', $fieldName . "_op", ts('Operator:'), $operations);
            $this->addElement('select', $fieldName . "_value", null, $field['options']);
            break;

          case CRM_Report_FORM::OP_DATE :
            // build datetime fields
            $this->addDate($fieldName . '_from', 'From:', false, array('formatType' => 'searchDate'));
            $count++;
            $this->addDate($fieldName . '_to', 'To:', false, array('formatType' => 'searchDate'));
            $count++;
            break;

          default:
            // default type is string
            $this->addElement('select', "{$fieldName}_op", ts('Operator:'), $operations, array('onchange' => "return showHideMaxMinVal( '$fieldName', this.value );"));
            // we need text box for value input
            $this->add('text', "{$fieldName}_value", null);
            break;
        }
      }
    }
    $this->assign('filters', $filters);
  }

}
