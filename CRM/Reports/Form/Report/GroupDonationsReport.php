<?php

class CRM_Reports_Form_Report_GroupDonationsReport extends CRM_Report_Form {

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
    $this->assign('reportTitle', ts('Donations Report'));
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
        'group'          => array('title' => ts('Group')),
        'amount'          => array('title' => ts('Total amount')),
        'count'            => array('title' => ts('Total contributions')),
    );

    // get the acl clauses built before we assemble the query
    $sql = "SELECT '1' AS `sort`,`group`.`id` AS `gid`,`group`.`title` AS `group`, SUM(contribution.total_amount) AS amount, COUNT(contribution.id) AS `count`
            FROM `civicrm_group` `group`
            LEFT JOIN `civicrm_group_contact` `gc` ON `group`.`id` = `gc`.`group_id` 
            LEFT JOIN `civicrm_subscription_history` `csh_added` ON `gc`.`contact_id` = `csh_added`.`contact_id` AND `group`.`id` = `csh_added`.`group_id` AND `csh_added`.`status` = 'Added' 
            LEFT JOIN `civicrm_subscription_history` `csh_removed` ON `gc`.`contact_id` = `csh_removed`.`contact_id` AND `group`.`id` = `csh_removed`.`group_id` AND `csh_removed`.`status` = 'Removed' 
            LEFT JOIN `civicrm_contribution` `contribution` ON 
              `gc`.`contact_id` = `contribution`.`contact_id` AND 
              contribution.receive_date >= '".$startDate."' AND 
              contribution.receive_date <= '".$endDate."' AND 
              (`csh_added`.`date` IS NULL OR `csh_added`.`date` <= `contribution`.`receive_date`) AND 
              (`csh_removed`.`date` IS NULL OR `csh_removed`.`date` >= `contribution`.`receive_date`)
          WHERE `group`.`id` IN (".implode(',', $this->group_ids).") 
          GROUP BY `group`.`id`
          
          UNION SELECT '2' AS `sort`,0 AS `gid`, 'Other' AS `group`, SUM(contribution1.total_amount) AS amount, COUNT(*) AS `count`
          FROM `civicrm_contribution` `contribution1`
          WHERE contribution1.receive_date >= '".$startDate."' AND contribution1.receive_date <= '".$endDate."' 
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
              (`csh_removed`.`date` IS NULL OR `csh_removed`.`date` >= `contribution`.`receive_date`)
            WHERE `group`.`id` IN (".implode(',', $this->group_ids).") 
            AND contribution.id IS NOT NULL
          )
          ORDER BY `sort`, `group`";
        

    $rows = array();
    $this->buildRows($sql, $rows);

    $this->formatDisplay($rows);
    $this->doTemplateAssignment($rows);
    $this->endPostProcess($rows);
  }

  function alterDisplay(&$rows) {
    // custom code to alter rows
    
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
