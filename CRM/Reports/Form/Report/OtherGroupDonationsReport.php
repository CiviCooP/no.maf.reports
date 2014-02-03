<?php

class CRM_Reports_Form_Report_OtherGroupDonationsReport extends CRM_Report_Form {

  protected $_addressField = FALSE;

  protected $_emailField = FALSE;

  protected $_summary = NULL;

  protected $_customGroupGroupBy = FALSE; 
  protected $_add2groupSupported = FALSE;
  
  protected $group_ids = array();
  
  protected $_exposeContactID = FALSE;
  
  function __construct() {

    $this->_columns = array(
      'civicrm_contact' => array(
          'dao' => 'CRM_Contact_DAO_Contact',
          'filters' => array(
              'contribution_date' => array(
                  'title'         => ts('Contribution date'),
                  'operatorType'  => CRM_Report_Form::OP_DATE)
              ),
      ),
    );
    $this->_groupFilter = FALSE;
    $this->_tagFilter = FALSE;
    parent::__construct();
    
    $donor_groups = CRM_Contact_BAO_Group::getGroups(array('title' => 'Donors'));
    $donorGroupIds = array();
    foreach($donor_groups as $group) {
      $donorGroupIds[] = $group->id;
    }
    if (count($donorGroupIds)) {
      $this->group_ids = CRM_Contact_BAO_GroupNesting::getDescendentGroupIds($donorGroupIds, false);
    }
  }

  function preProcess() {
    $this->assign('reportTitle', ts('Donations in group other Report'));
    parent::preProcess();
  }
  
  function postProcess() {

    $this->beginPostProcess();
    
    $startDate = date('Ymd');
    $endDate = date('Ymd');
    
    if (isset($this->_submitValues['contribution_date_from'])) {
      if (!empty($this->_submitValues['contribution_date_from'])) {
          $startDate = date("Ymd", strtotime($this->_submitValues['contribution_date_from']));
      }
    }
    if (isset($this->_submitValues['contribution_date_to'])) {
        if (!empty($this->_submitValues['contribution_date_to'])) {
            $endDate = date("Ymd", strtotime($this->_submitValues['contribution_date_to']));
        }
    }
    
    $this->_columnHeaders = array(
        'contact_id'            => array('title' => ts('Id')),
        'display_name'          => array('title' => ts('Contact')),
        'total_amount'          => array('title' => ts('Total amount')),
        'receive_date'            => array('title' => ts('Receive date')),
    );

    // get the acl clauses built before we assemble the query
    $sql = "SELECT `contact`.`id` as `contact_id`, `contact`.`display_name`, `contribution1`.`total_amount`, `contribution1`.`receive_date`
          FROM `civicrm_contribution` `contribution1` INNER JOIN `civicrm_contact` `contact` ON `contribution1`.`contact_id` = `contact`.`id`
          WHERE contribution1.receive_date >= '".$startDate."' AND contribution1.receive_date <= '".$endDate."' AND contribution1.contribution_status_id = 1
          AND contribution1.id NOT IN (
            SELECT contribution.id
            FROM `civicrm_group` `group`
            LEFT JOIN `civicrm_group_contact` `gc` ON `group`.`id` = `gc`.`group_id` 
            LEFT JOIN `civicrm_subscription_history` `csh_added` ON `gc`.`contact_id` = `csh_added`.`contact_id` AND `group`.`id` = `csh_added`.`group_id` AND `csh_added`.`status` = 'Added' 
            LEFT JOIN `civicrm_subscription_history` `csh_removed` ON `gc`.`contact_id` = `csh_removed`.`contact_id` AND `group`.`id` = `csh_removed`.`group_id` AND `csh_removed`.`status` = 'Removed' 
            LEFT JOIN `civicrm_contribution` `contribution` ON 
              `gc`.`contact_id` = `contribution`.`contact_id` AND 
              contribution.receive_date >= '".$startDate."' AND 
              contribution.receive_date <= '".$endDate."' AND 
              (`csh_added`.`date` IS NULL OR `csh_added`.`date` <= `contribution`.`receive_date`) AND 
              (`csh_removed`.`date` IS NULL OR `csh_removed`.`date` >= `contribution`.`receive_date`) AND
              contribution.contribution_status_id = 1
            WHERE `group`.`id` IN (".implode(',', $this->group_ids).") 
            AND contribution.id IS NOT NULL
          )
          ORDER BY `contact`.`display_name`";
        

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
          $url = CRM_Utils_System::url( "civicrm/contact/view",
              'reset=1&cid=' . $row['contact_id'], $this->_absoluteUrl );
          $rows[$rowNum]['display_name_link' ] = $url;
          $rows[$rowNum]['display_name_hover'] = ts("View Contact details for this contact.");
          $entryFound = true;
      }
      if (array_key_exists('contact_id', $row)) {
          $url = CRM_Utils_System::url( "civicrm/contact/view",
              'reset=1&cid=' . $row['contact_id'], $this->_absoluteUrl );
          $rows[$rowNum]['contact_id_link' ] = $url;
          $rows[$rowNum]['contact_id_hover'] = ts("View Contact details for this contact.");
          $entryFound = true;
      }
      // skip looking further in rows, if first row itself doesn't
      // have the column we need
      if ( !$entryFound ) {
          break;
      }
    }    
  }
  
  function addFilters( ) {
    require_once 'CRM/Utils/Date.php';
    require_once 'CRM/Core/Form/Date.php';
    $count = 1;
    foreach ( $this->_filters as $table => $attributes ) {
        foreach ( $attributes as $fieldName => $field ) {
            // get ready with option value pair
            $operations = $this->getOperationPair( CRM_Utils_Array::value( 'operatorType', $field ),
                                                   $fieldName );

            $filters[$table][$fieldName] = $field;

            switch ( CRM_Utils_Array::value( 'operatorType', $field )) {
            case CRM_Report_FORM::OP_SELECT :
                // assume a select field
                $this->addElement('select', $fieldName."_op", ts( 'Operator:' ), $operations);
                $this->addElement('select', $fieldName."_value", null, $field['options']);
                break;

            case CRM_Report_FORM::OP_DATE :
                // build datetime fields
                $this->addDate( $fieldName.'_from','From:', false, array( 'formatType' => 'searchDate' ) );
                $count++;
                $this->addDate( $fieldName.'_to','To:', false, array( 'formatType' => 'searchDate' ) );
                $count++;
                break;

            default:
                // default type is string
                $this->addElement('select', "{$fieldName}_op", ts( 'Operator:' ), $operations,
                                  array('onchange' =>"return showHideMaxMinVal( '$fieldName', this.value );"));
                // we need text box for value input
                $this->add( 'text', "{$fieldName}_value", null );
                break;
            }
        }
    }
    $this->assign( 'filters', $filters );
  }
  
  
}
