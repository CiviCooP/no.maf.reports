<?php

class CRM_Reports_Form_Report_GroupMembership extends CRM_Report_Form {

  protected $_addressField = FALSE;
  protected $_emailField = FALSE;
  protected $_summary = NULL;
  protected $_customGroupExtends = array();
  protected $_customGroupGroupBy = FALSE;
  protected $_noFields = TRUE;

  function __construct() {

    /*
     * retrieve all groups that have children
     * if there is a group called Donors, set to default
     * (specific to MAF Norge)
     */
    $groupParams = array(
      'options' => array('limit' => 99999)
    );
    $apiGroups = civicrm_api3('Group', 'Get', $groupParams);
    $groupList = array();
    $groupDefault = 0;
    foreach ($apiGroups['values'] as $groupId => $apiGroup) {
      if (!empty($apiGroup['children'])) {
        $groupList[$groupId] = $apiGroup['title'];
      }
      if ($apiGroup['title'] == "Donors") {
        $groupDefault = $groupId;
      }
    }
    /*
     * default period is last month
     */
    $now = new DateTime();
    $base = $now->modify('-1 month');
    $month = (int) $base->format("m");
    $year = (int) $base->format("Y");
    $this->_defaultFromPeriod = "01/" . $month . "/" . $year;
    /*
     * array with columns and filters
     */
    $this->_columns = array(
      'civicrm_contact' => array(
        'dao' => 'CRM_Contact_DAO_Contact',
        'filters' => array(
          'parent' => array(
            'title' => ts('Parent Group'),
            'operatorType' => CRM_Report_Form::OP_SELECT,
            'required' => TRUE,
            'default' => 6508,
            'options' => $groupList
          ),
          'period' => array(
            'title' => ts('Period'),
            'operatorType' => CRM_Report_Form::OP_DATE
          )
        ),
      ),
    );
    $this->_groupFilter = FALSE;
    $this->_tagFilter = FALSE;
    $this->_exposeContactID = FALSE;
    $this->_add2groupSupported = FALSE;

    $this->_customGroupExtends = "";
    parent::__construct();
  }

  function preProcess() {
    $this->assign('reportTitle', ts('Group membership'));
    parent::preProcess();
  }

  function postProcess() {
    $this->beginPostProcess();
    /*
     * retrieve all subscription history in selected period for 
     * child groups of parent selected
     */
    $submitValues = $this->getVar('_submitValues');
    /*
     * retrieve children from selected parents
     */
    $parentGroupParams = array('id' => $submitValues['parent_value']);
    $parentGroup = civicrm_api3('Group', 'Getsingle', $parentGroupParams);    
    $periodFrom = date("Y-m-d", strtotime($submitValues['period_from']));
    $periodTo = date("Y-m-d", strtotime($submitValues['period_to']));
    
    $this->_columnHeaders = array(
      'group' => array('title' => 'Group'),
      'members_start' => array('title' => 'Members on '.$submitValues['period_from_display']),
      'members_end' => array('title' => 'Members on '.$submitValues['period_to_display']),
      'growth' => array('title' => 'Growth'),
    );
    
    $sql = "SELECT * FROM `civicrm_group` WHERE `id` IN (".$parentGroup['children'].")";
    $rows = array();
    $dao = CRM_Core_DAO::executeQuery($sql);
    while ($dao->fetch()) {
      $row = array();
      $row['group'] = $dao->title;
      $row['members_start'] = '0';
      $row['members_end'] = '0';
      $row['growth'] = '';
      $rows[$dao->id] = $row;
    }
    
    $sql = "select csh1.group_id as group_id, count(*) as total 
            from civicrm_subscription_history csh1
            where csh1.group_id in (".$parentGroup['children'].") and `date` = (
              SELECT max(`date`) 
              FROM civicrm_subscription_history csh2
              INNER JOIN civicrm_contact c on csh2.contact_id = c.id
              WHERE csh2.group_id = csh1.group_id AND csh2.contact_id = csh1.contact_id
                and `date` <= '".$periodFrom."' AND c.is_deleted !=  '1'
            ) and `status` = 'Added'
            GROUP BY csh1.group_id;";

    $dao = CRM_Core_DAO::executeQuery($sql);
    while ($dao->fetch()) {
      if (isset($rows[$dao->group_id])) {
        $rows[$dao->group_id]['members_start'] = $dao->total;
      }
    }
    
    $sql = "select csh1.group_id as group_id, count(*) as total
            from civicrm_subscription_history csh1
            where csh1.group_id in (".$parentGroup['children'].") and `date` = (
              SELECT max(`date`) 
              FROM civicrm_subscription_history csh2
              INNER JOIN civicrm_contact c on csh2.contact_id = c.id
              WHERE csh2.group_id = csh1.group_id AND csh2.contact_id = csh1.contact_id
                and `date` <= '".$periodTo."' AND c.is_deleted !=  '1'
            ) and `status` = 'Added'
            GROUP BY csh1.group_id;";
    $dao = CRM_Core_DAO::executeQuery($sql);
    while ($dao->fetch()) {
      if (isset($rows[$dao->group_id])) {
        $rows[$dao->group_id]['members_end'] = $dao->total;
        
        $growth = $rows[$dao->group_id]['members_end'] - $rows[$dao->group_id]['members_start'];
        if ($rows[$dao->group_id]['members_start'] == 0) {
          $percentage = $growth * 100;
        } else {
          $percentage = abs($growth) * 100 / $rows[$dao->group_id]['members_start'];
        }
        if ($growth > 0) {
          $rows[$dao->group_id]['growth'] = '+'.abs($growth).' (<span style="color:green">+'.number_format($percentage, 2).'%</span>)';
        } elseif ($growth < 0) {
          $rows[$dao->group_id]['growth'] = '-'.abs($growth).' (<span style="color:red">-'.number_format($percentage, 2).'%</span>)';
        } else {
          $rows[$dao->group_id]['growth'] = '';
        }        
      }
    }

    $this->formatDisplay($rows);
    $this->doTemplateAssignment($rows);
    $this->endPostProcess($rows);
  }

}
