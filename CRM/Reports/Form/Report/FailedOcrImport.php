<?php
/**
 * CiviCRM report Failed OCR Imports (MAF Norge)
 * 
 * @author Erik Hommel (erik.hommel@civicoop.org, http://www.civicoop.org)
 */
require_once 'CRM/Report/Form.php';

class CRM_Reports_Form_Report_FailedOcrimport extends CRM_Report_Form {
    protected $_addressField = FALSE;
    protected $_emailField = FALSE;
    protected $_summary = NULL;
    protected $_customGroupExtends = array('');
    protected $_customGroupGroupBy = FALSE; 
    
    function __construct() {
        /*
         * create array with columns and filters
         */
        $this->_columns = array(
            'civicrm_contact' => array(
                'dao' => 'CRM_Contact_DAO_Contact',
                'fields' => array(
                    'display_name' => array(
                        'title'     => ts('Contact Name'),
                        'required'  => TRUE,
                        'default'   => TRUE,
                        'no_repeat' => TRUE,
                    ),
                    'id' => array(
                        'no_display'    => TRUE,
                        'required'      => TRUE,
                    ),
                ),
                'filters' => array(
                    'transmission' => array(
                        'title'         => ts('Transmission Number'),
                        'operatorType'  => CRM_Report_Form::OP_SELECT,
                        'options'       =>  $transmissionOptions
                    ),
                    'import_date' => array(
                        'title'         => ts('Import Date'),
                        'operatorType'  => CRM_Report_Form::OP_DATE)
                    ),
            ),
        );
        $this->_groupFilter = FALSE;
        $this->_tagFilter = FALSE;
        parent::__construct();
    }
  
    function preProcess() {
        $this->assign('reportTitle', ts('Failed OCR Imports'));
        parent::preProcess();
    }

    function select() {
        $this->_select = "SELECT failed.*, kid.contact_id, contact.display_name";
    }

    function from() {
        $this->_from = NULL;
        $this->_from = "
FROM civicrm_failed_kid_numbers failed
LEFT JOIN civicrm_kid_number kid ON failed.kid_number = kid.kid_number
LEFT JOIN civicrm_contact contact ON kid.contact_id = contact.id";
    }

    function where() {
        $this->_where = NULL;
    }
    function groupBy() {
        $this->_groupBy = NULL;
    }
    function orderBy() {
        $this->_orderBy = " ORDER BY failed.import_date DESC";
    }
    function postProcess() {
        $this->beginPostProcess();
        
        $this->_columnHeaders = array(
            'display_name'          => array('title' => ts('Contact')),
            'kid_number'            => array('title' => ts('KID number')),
            'transmission_number'   => array('title' => ts('Transmission Number')),
            'bank_date'             => array('title' => ts('Bank date')),
            'import_date'           => array('title' => ts('Import date')),
            'amount'                => array('title' => ts('Amount')),
            'transaction_number'    => array('title' => ts('Transaction Number')),
            'fail_message'          => array('title' => ts('Message'))
        );
        
        // get the acl clauses built before we assemble the query
        $this->buildACLClause($this->_aliases['civicrm_contact']);
        $sql = $this->buildQuery(TRUE);
        
        $rows = array();
        $this->buildRows($sql, $rows);
        
        $this->formatDisplay($rows);
        $this->doTemplateAssignment($rows);
        $this->endPostProcess($rows);
    }
    function alterDisplay(&$rows) {
        $entryFound = false;
        foreach ($rows as $rowNum => $row) {
            // make count columns point to detail report
            // convert display name to links
            if (array_key_exists('display_name', $row)) {
                $url = CRM_Utils_System::url( "civicrm/contact/view",
                    'reset=1&cid=' . $row['id'], $this->_absoluteUrl );
                $rows[$rowNum]['display_name_link' ] = $url;
                $rows[$rowNum]['display_name_hover'] = ts("View Contact details for this contact.");
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
                    $this->addDate( $fieldName.'_from','Van:', false, array( 'formatType' => 'searchDate' ) );
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
}
