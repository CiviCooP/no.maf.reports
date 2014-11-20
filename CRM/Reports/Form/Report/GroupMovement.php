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
        $groupParams = array(
            'options'   => array('limit' => 99999)
        );
        $apiGroups = civicrm_api3('Group', 'Get', $groupParams);
        $groupList = array();
        $groupDefault = 0;
        foreach($apiGroups['values'] as $groupId => $apiGroup) {
            if (!empty($apiGroup['children'])) {
                $groupList[$groupId] = $apiGroup['title'];
            }
            if ($apiGroup['title'] == "Donor Journeys") {
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
                        'default'       => $groupDefault,
                        'options'       => $groupList
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
        $submitValues = $this->getVar('_submitValues');
        if (!empty($submitValues)) {
            /*
             * retrieve children from selected parents
             */
            if (!empty($submitValues['parent_value'])) {
                $parentGroupParams = array('id' => $submitValues['parent_value']);
                $parentGroup = civicrm_api3('Group', 'Getsingle', $parentGroupParams);
                $sql = "SELECT DISTINCT(group_id) FROM civicrm_subscription_history WHERE group_id IN (";
                $sql .= $parentGroup['children'].")";
            }
            /*
             * add date range if required
             */
            if (!empty($submitValues['period_from'])) {
                $periodFrom = date("Y-m-d", strtotime($submitValues['period_from']));
            }
            if (!empty($submitValues['period_to'])) {
                $periodTo = date("Y-m-d", strtotime($submitValues['period_to']));
            }
            if ($periodFrom && $periodTo) {
                $this->_whereDate = " AND date BETWEEN '$periodFrom' AND '$periodTo'";
            } else {
                if ($periodFrom) {
                    $this->_whereDate = " AND date >= '$periodFrom'";
                }
                if ($periodTo) {
                    $this->_whereDate = " AND date <= '$periodTo'";
                }
            }
            $sql .= $this->_whereDate." ORDER BY group_id";

            $rows = array();
            $this->buildRows($sql, $rows);
        }

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
        $filters = array();
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
        $previousGroup = 0;
        /*
         * read all selected groups
         */
        while ($dao->fetch()) {
            $row = array();
            /*
             * build row for each group
             */
            $childGroup = civicrm_api3('Group', 'Getsingle', array('id' => $dao->group_id));
            $row['group_id'] = $dao->group_id;
            $row['group'] = $childGroup['title'];
            
            $groupAddsQuery = 
"SELECT COUNT(*) AS count_adds FROM civicrm_subscription_history WHERE 
    group_id = {$dao->group_id} AND status = 'Added'".$this->_whereDate;
            $daoAdds = CRM_Core_DAO::executeQuery($groupAddsQuery);
            if ($daoAdds->fetch()) {
                $row['adds'] = $daoAdds->count_adds;
            }
            unset($daoAdds, $groupAddsQuery);
            
            $groupRemovesQuery = 
"SELECT COUNT(*) AS count_removes FROM civicrm_subscription_history WHERE 
    group_id = {$dao->group_id} AND status = 'Removed'".$this->_whereDate;
            $daoRemoves = CRM_Core_DAO::executeQuery($groupRemovesQuery);
            if ($daoRemoves->fetch()) {
                $row['removes'] = $daoRemoves->count_removes;
            } 
            unset($daoRemoves, $groupRemovesQuery);
            
            $groupDeletesQuery = 
"SELECT COUNT(*) AS count_deletes FROM civicrm_subscription_history WHERE 
    group_id = {$dao->group_id} AND status = 'Deleted'".$this->_whereDate;
            $daoDeletes = CRM_Core_DAO::executeQuery($groupDeletesQuery);
            if ($daoDeletes->fetch()) {
                $row['deletes'] = $daoDeletes->count_deletes;
            } 
            unset($daoDeletes, $groupDeletesQuery);
            
            //$row['detail'] = $this->createMovementDetail($dao->group_id);
            
            $rows[] = $row;
            
        }
    }
    /**
     * Function to create the movement detail within the group. This
     * is how many where added to group x, came from group z etc.
     * 
     * @todo function not used now, needs to be discussed with Steinar at sprint
     * 
     * @author Erik Hommel (erik.hommel@civicoop.org)
     * @date 5 Feb 2014
     * @param int $groupId
     * @return string $detail
     */
    function createMovementDetail($groupId) {
        $detail = "";
        if (empty($groupId) || !is_numeric($groupId)) {
            return $detail;
        }
        /*
         * first select all contacts that have history in the group in the
         * selected period and store them in array
         */
        $movementContacts = array();
        $movementQuery = 
"SELECT DISTINCT(contact_id) FROM civicrm_subscription_history 
    WHERE group_id = $groupId".$this->_whereDate;       
        $daoMovement = CRM_Core_DAO::executeQuery($movementQuery);
        while ($daoMovement->fetch()) {
            $movementContacts[] = $daoMovement->contact_id;
        }
        unset($movementQuery, $daoMovement);
        
        $movementTotals = array();
        /*
         * now select subscription history for each contact and
         * add to group element in total array
         */
        foreach ($movementContacts as $contact) {
            $contactQuery = 
"SELECT * FROM civicrm_subscription_history WHERE group_id <> $groupId AND contact_id = $contact".$this->_whereDate;
            $daoContact = CRM_Core_DAO::executeQuery($contactQuery);
            while ($daoContact->fetch()) {
                if (!in_array($daoContact->group_id, $movementTotals)) {
                    if ($daoContact->status == "Added") {
                        $movementTotals[$daoContact->group_id['to']] = 1;
                        $movementTotals[$daoContact->group_id['from']] = 0;
                    } else {
                        $movementTotals[$daoContact->group_id['to']] = 0;
                        $movementTotals[$daoContact->group_id['from']] = 1;
                    }
                } else {
                    if ($daoContact->status == "Added") {
                        $movementTotals[$daoContact->group_id['to']]++;
                    } else {
                        $movementTotals[$daoContact->group_id['from']]++;                        
                    }
                }
            }
            unset($daoContact, $contactQuery);
        return $detail;
        }
    }
}
