<?php
/**
 * CiviCRM report Group Movement (MAF Norge)
 * 
 * This report lets the user select a top group and a period. The report
 * then shows the group movement for all the child groups of the selected
 * group. With group movement we mean how many were added and removed, and where
 * they came from and went to
 * 
 * 
 * @author Erik Hommel (erik.hommel@civicoop.org, http://www.civicoop.org)
 * @date 5 Feb 2014
 * @license Academic Free License V3.0 (http://opensource.org/licenses/academic.php)
 */

class CRM_Reports_Form_Report_GroupMovement extends CRM_Report_Form {
    protected $_addressField = FALSE;
    protected $_emailField = FALSE;
    protected $_summary = NULL;
    protected $_customGroupExtends = array('');
    protected $_customGroupGroupBy = FALSE;
    protected $_whereDate = "";
    /*
     * Constructor
     */
    function __construct() {
        
        /*
         * retrieve all groups that have children
         * if there is a group called Donors, set to default
         * (specific to MAF Norge)
         */
        $group_params = array(
            'options'   => array('limit' => 99999)
        );
        $groups_api = civicrm_api3('Group', 'Get', $group_params);
        $group_list = array();
        $group_default = 0;
        foreach($groups_api['values'] as $group_id => $group_api) {
            if (!empty($group_api['children'])) {
                $group_list[$group_id] = $group_api['title'];
            }
            if ($group_api['title'] == "Donors") {
                $group_default = $group_id;
            }
        }
        /*
         * default period is last month
         */
        $now = new DateTime();
        $base = $now->modify('-1 month');
        $month = (int) $base->format("m");
        $year = (int) $base->format("Y");
        $this->_defaultFromPeriod = "01/".$month."/".$year;
        /*
         * array with columns and filters
         */
        $this->_columns = array(
            'civicrm_contact' => array(
                'dao' => 'CRM_Contact_DAO_Contact',
                'filters' => array(
                    'parent' => array(
                        'title'         => ts('Parent Group'),
                        'operatorType'  => CRM_Report_Form::OP_SELECT,
                        'required'      => TRUE,
                        'default'       => 6508,
                        'options'       => $group_list
                    ),
                    'period' => array(
                        'title'         => ts('Period'),
                        'operatorType'  => CRM_Report_Form::OP_DATE
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
        $this->assign('reportTitle', ts('Group Movement Report'));
        /*
        * set default for group to Donor (specific for MAF Norge);
        */
        parent::preProcess();
    }
    function postProcess() {
        $this->_columnHeaders = array(
            'group'     => array('title' => 'Group'),
            'adds'	=> array('title' => 'Added'),
            'removes'   => array('title' => 'Removed'),
            'deletes'   => array('title' => 'Deleted'),
            'details'   => array('title' => 'Details')
           );

        $this->beginPostProcess();
    
        // get the acl clauses built before we assemble the query
        $this->buildACLClause($this->_aliases['civicrm_contact']);
        /*
         * retrieve all subscription history in selected period for 
         * child groups of parent selected
         */
        $submit_values = $this->getVar('_submitValues');
        /*
         * retrieve children from selected parents
         */
        $parent_group_params = array('id' => $submit_values['parent_value']);
        $parent_group = civicrm_api3('Group', 'Getsingle', $parent_group_params);
        $sql = "SELECT DISTINCT(group_id) FROM civicrm_subscription_history WHERE group_id IN (";
        $sql .= $parent_group['children'].")";
        /*
         * add date range if required
         */
        if (!empty($submit_values['period_from'])) {
            $period_from = date("Y-m-d", strtotime($submit_values['period_from']));
        }
        if (!empty($submit_values['period_to'])) {
            $period_to = date("Y-m-d", strtotime($submit_values['period_to']));
        }
        if ($period_from && $period_to) {
            $this->_whereDate = " AND date BETWEEN '$period_from' AND '$period_to'";
        } else {
            if ($period_from) {
                $this->_whereDate = " AND date >= '$period_from'";
            }
            if ($period_to) {
                $this->_whereDate = " AND date <= '$period_to'";
            }
        }
        $sql .= $this->_whereDate." ORDER BY group_id";

        $rows = array();
        $this->buildRows($sql, $rows);

        $this->formatDisplay($rows);
        $this->doTemplateAssignment($rows);
        $this->endPostProcess($rows);
    }

    function alterDisplay(&$rows) {
        // custom code to alter rows
        $entryFound = FALSE;
        $checkList = array();
        foreach ($rows as $rowNum => $row) {
            if (!$entryFound) {
                break;
            }
        }
    }
    function addFilters( ) {
        require_once 'CRM/Utils/Date.php';
        require_once 'CRM/Core/Form/Date.php';
        $options = $filters = array();
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
                    if (!empty($this->_defaultFromPeriod)) {
                        $this->addDate( $fieldName.'_from','Van:', false, array( 'formatType' => 'searchDate'));
                    }
                    $count++;
                    $this->addDate( $fieldName.'_to','Tot:', false, array( 'formatType' => 'searchDate' ) );
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
    function buildRows($sql, &$rows ) {
        $dao = CRM_Core_DAO::executeQuery($sql);
        
        // use this method to modify $this->_columnHeaders
        $this->modifyColumnHeaders( );
        $rows = array();
        $previous_group = 0;
        /*
         * read all selected groups
         */
        while ($dao->fetch()) {
            $row = array();
            /*
             * build row for each group
             */
            $child_group = civicrm_api3('Group', 'Getsingle', array('id' => $dao->group_id));
            $row['group_id'] = $dao->group_id;
            $row['group'] = $child_group['title'];
            
            $group_adds_query = 
"SELECT COUNT(*) AS count_adds FROM civicrm_subscription_history WHERE 
    group_id = {$dao->group_id} AND status = 'Added'".$this->_whereDate;
            $dao_adds = CRM_Core_DAO::executeQuery($group_adds_query);
            if ($dao_adds->fetch()) {
                $row['adds'] = $dao_adds->count_adds;
            }
            unset($dao_adds, $group_adds_query);
            
            $group_removes_query = 
"SELECT COUNT(*) AS count_removes FROM civicrm_subscription_history WHERE 
    group_id = {$dao->group_id} AND status = 'Removed'".$this->_whereDate;
            $dao_removes = CRM_Core_DAO::executeQuery($group_removes_query);
            if ($dao_removes->fetch()) {
                $row['removes'] = $dao_removes->count_removes;
            } 
            unset($dao_removes, $group_removes_query);
            
            $group_deletes_query = 
"SELECT COUNT(*) AS count_deletes FROM civicrm_subscription_history WHERE 
    group_id = {$dao->group_id} AND status = 'Deleted'".$this->_whereDate;
            $dao_deletes = CRM_Core_DAO::executeQuery($group_deletes_query);
            if ($dao_deletes->fetch()) {
                $row['deletes'] = $dao_deletes->count_deletes;
            } 
            unset($dao_deletes, $group_deletes_query);
            
            $row['detail'] = $this->createMovementDetail($dao->group_id);
            
            $rows[] = $row;
            
        }
    }
    /**
     * Function to create the movement detail within the group. This
     * is how many where added to group x, came from group z etc.
     * 
     * @author Erik Hommel (erik.hommel@civicoop.org)
     * @date 5 Feb 2014
     * @param int $group_id
     * @return string $detail
     */
    function createMovementDetail($group_id) {
        $detail = "";
        if (empty($group_id) || !is_numeric($group_id)) {
            return $detail;
        }
        /*
         * first select all contacts that have history in the group in the
         * selected period and store them in array
         */
        $movement_contacts = array();
        $movement_query = 
"SELECT DISTINCT(contact_id) FROM civicrm_subscription_history 
    WHERE group_id = $group_id".$this->_whereDate;
        $dao_movement = CRM_Core_DAO::executeQuery($movement_query);
        while ($dao_movement->fetch()) {
            $movement_contacts[] = $dao_movement->contact_id;
        }
        unset($movement_query, $dao_movement);
        
        $movement_totals = array();
        /*
         * now select subscription history for each contact and
         * add to group element in total array
         */
        foreach ($movement_contacts as $contact) {
            $contact_query = 
"SELECT * FROM civicrm_subscription_history WHERE contact_id = $contact".$this->_whereDate;
            $dao_contact = CRM_Core_DAO::executeQuery($contact_query);
            while ($dao_contact->fetch()) {
                if (!in_array($dao_contact->group_id, $movement_totals)) {
                    if ($dao_contact->status == "Added") {
                        $movement_totals[$dao_contact->group_id['to']] = 1;
                        $movement_totals[$dao_contact->group_id['from']] = 0;
                    } else {
                        $movement_totals[$dao_contact->group_id['to']] = 0;
                        $movement_totals[$dao_contact->group_id['from']] = 1;
                    }
                } else {
                    if ($dao_contact->status == "Added") {
                        $movement_totals[$dao_contact->group_id['to']]++;
                    } else {
                        $movement_totals[$dao_contact->group_id['from']]++;                        
                    }
                }
            }
            unset($dao_contact, $contact_query);
        return $detail;
    }
}
