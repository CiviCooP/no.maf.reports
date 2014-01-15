<?php
/**
 * CiviCRM report Printed Giro's (MAF Norge)
 * 
 * This report shows all pending contributions for Printed Giro's, and
 * sets the custom field 'sent to bank' to yes if a CSV has been
 * generated for this report
 * 
 * @author Erik Hommel (erik.hommel@civicoop.org, http://www.civicoop.org)
 */

require_once 'CRM/Report/Form.php';

class CRM_Reports_Form_Report_PrintedGiro extends CRM_Report_Form {
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
                'filters' => array(
                    'receive' => array(
                        'title'         => ts('Contribution Receive Date'),
                        'operatorType'  => CRM_Report_Form::OP_DATE)
                    ),
            ),
        );
        $this->_groupFilter = FALSE;
        $this->_tagFilter = FALSE;
        $this->_exposeContactID = FALSE;
        $this->_customGroupExtends = "";
        parent::__construct();
    }
  
    function preProcess() {
        $this->assign('reportTitle', ts('Report Printed Giros'));
        parent::preProcess();
    }

    function select() {
        $this->_select = 
"SELECT recur.amount, contact.display_name, address.street_address, address.postal_code, address.city, 
    fin.name AS financial_type, contr.id AS contribution_id, country.name AS country, recur.contact_id, 
    address.supplemental_address_1, contact.gender_id, contact.birth_date, contact.nick_name, '' as kid_number";
    }

    function from() {
        $this->_from = NULL;
        /*
         * FROM does not link into civicrm_kid_number, this is retrieved in the
         * alterDisplay function so we can check if there is one for the
         * contribution entity
         */
        $this->_from = 
"FROM civicrm_contribution contr
JOIN civicrm_contribution_recur recur ON contr.contribution_recur_id = recur.id
LEFT JOIN civicrm_contact contact ON recur.contact_id = contact.id
LEFT JOIN civicrm_address address ON contact.id = address.contact_id
LEFT JOIN civicrm_financial_type fin ON recur.financial_type_id = fin.id
LEFT JOIN civicrm_contribution_recur_offline off ON contr.contribution_recur_id = off.recur_id
LEFT JOIN civicrm_country country ON address.country_id = country.id";
    }

    function where() {
        $this->_where = NULL;
        $this->_where = "WHERE off.payment_type_id = 3 AND contr.contribution_status_id = 2";
        if (isset($this->_submitValues['receive_from'])) {
            if (!empty($this->_submitValues['receive_from'])) {
                $this->_where .= " AND contr.receive_date >= '".date("Y-m-d", strtotime($this->_submitValues['receive_from']))."'";
            }
        }
        if (isset($this->_submitValues['receive_to'])) {
            if (!empty($this->_submitValues['receive_to'])) {
                $this->_where .= " AND contr.receive_date <= '".date("Y-m-d", strtotime($this->_submitValues['receive_to']))."'";
            }
        }
    }
    function groupBy() {
        $this->_groupBy = NULL;
    }
    function orderBy() {
        $this->_orderBy = NULL;
    }
    function postProcess() {
        $this->beginPostProcess();
        
        $this->_columnHeaders = array(
            'display_name'              => array('title' => ts('Contact')),
            'supplemental_address_1'    => array('title' => ts('Add. Address')),
            'street_address'            => array('title' => ts('Address')),
            'postal_code'               => array('title' => ts('Postal Code')),
            'city'                      => array('title' => ts('City')),
            'country'                   => array('title' => ts('Country')),
            'contact_id'                => array('title' => ts('Contact ID')),
            'amount'                    => array('title' => ts('Amount')),
            'financial_type'            => array('title' => ts('Financial Type')),
            'kid_number'                => array('title' => ts('KID number')),
            'contribution_id'           => array('no_display' => TRUE),
            'gender_id'                 => array('title' => ts('Gender')),
            'birth_date'                => array('title' => ts("Birth Date")),
            'nick_name'                 => array('title' => ts("Nickname"))
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
            if (array_key_exists('country', $row)) {
                if ($row['country'] == "Norway") {
                    $rows[$rowNum]['country'] = "";
                }
            }
            if (array_key_exists('gender_id', $row)) {
                switch($row['gender_id']) {
                    case 1:
                        $rows[$rowNum]['gender_id'] = ts("Female");
                        break;
                    case 2:
                        $rows[$rowNum]['gender_id'] = ts("Male");
                        break;
                    default:
                        $rows[$rowNum]['gender_id'] = ts("Unkown");
                        break;
                }
            }
            if (array_key_exists('kid_number', $row)) {
                $rows[$rowNum]['kid_number'] = $this->retrieveKidNumber($row['contribution_id']);
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
    /*
     * include endPostProcess (copied from parent) to enable setting 'send to bank' to yes when CSV button
     */
    function endPostProcess(&$rows = NULL) {
        if ($this->_outputMode == 'print' || $this->_outputMode == 'pdf' || $this->_sendmail ) {
            $content = $this->compileContent();
            $url = CRM_Utils_System::url("civicrm/report/instance/{$this->_id}", "reset=1", TRUE);
            
            if ($this->_sendmail) {
                $config = CRM_Core_Config::singleton();
                $attachments = array();
                
                if ($this->_outputMode == 'csv') {
                    $content = $this->_formValues['report_header'] . '<p>' . ts('Report URL') . ": {$url}</p>" . '<p>' . ts('The report is attached as a CSV file.') . '</p>' . $this->_formValues['report_footer'];
                    $csvFullFilename = $config->templateCompileDir . CRM_Utils_File::makeFileName('CiviReport.csv');
                    $csvContent = CRM_Report_Utils_Report::makeCsv($this, $rows);
                    file_put_contents($csvFullFilename, $csvContent);
                    $attachments[] = array(
                        'fullPath' => $csvFullFilename,
                        'mime_type' => 'text/csv',
                        'cleanName' => 'CiviReport.csv',
                    );
                }
                if ($this->_outputMode == 'pdf') {
                    // generate PDF content
                    $pdfFullFilename = $config->templateCompileDir . CRM_Utils_File::makeFileName('CiviReport.pdf');
                    file_put_contents($pdfFullFilename, CRM_Utils_PDF_Utils::html2pdf($content, "CiviReport.pdf", TRUE, array('orientation' => 'landscape')));
                    // generate Email Content
                    $content = $this->_formValues['report_header'] . '<p>' . ts('Report URL') . ": {$url}</p>" . '<p>' . ts('The report is attached as a PDF file.') . '</p>' . $this->_formValues['report_footer'];
                    $attachments[] = array(
                        'fullPath' => $pdfFullFilename,
                        'mime_type' => 'application/pdf',
                        'cleanName' => 'CiviReport.pdf',
                    );
                }

                if (CRM_Report_Utils_Report::mailReport($content, $this->_id, $this->_outputMode, $attachments)) {
                    CRM_Core_Session::setStatus(ts("Report mail has been sent."), ts('Sent'), 'success');
                } else {
                    CRM_Core_Session::setStatus(ts("Report mail could not be sent."), ts('Mail Error'), 'error');
                }
                
                CRM_Utils_System::redirect(CRM_Utils_System::url(CRM_Utils_System::currentPath(), 'reset=1'));
                
            } elseif ($this->_outputMode == 'print') {
                echo $content;
            } else {
                if ($chartType = CRM_Utils_Array::value('charts', $this->_params)) {
                    $config = CRM_Core_Config::singleton();
                    //get chart image name
                    $chartImg = $this->_chartId . '.png';
                    //get image url path
                    $uploadUrl = str_replace('/persist/contribute/', '/persist/', $config->imageUploadURL) . 'openFlashChart/';
                    $uploadUrl .= $chartImg;
                    //get image doc path to overwrite
                    $uploadImg = str_replace('/persist/contribute/', '/persist/', $config->imageUploadDir) . 'openFlashChart/' . $chartImg;
                    //Load the image
                    $chart = imagecreatefrompng($uploadUrl);
                    //convert it into formattd png
                    header('Content-type: image/png');
                    //overwrite with same image
                    imagepng($chart, $uploadImg);
                    //delete the object
                    imagedestroy($chart);
                }
                CRM_Utils_PDF_Utils::html2pdf($content, "CiviReport.pdf", FALSE, array('orientation' => 'landscape'));
            }
            CRM_Utils_System::civiExit();
        } elseif ($this->_outputMode == 'csv') {
            CRM_Report_Utils_Report::export2csv($this, $rows);
            /*
             * specific for this report: once a CSV file has been generated we assume
             * the printed giros have been send to the bank, and we set
             * the custom value in the specific custom file for NETS processing
             * to that value
             */
            $this->setSendToBank($rows);
            
        } elseif ($this->_outputMode == 'group') {
            $group = $this->_params['groups'];
            $this->add2group($group);
        } elseif ($this->_instanceButtonName == $this->controller->getButtonName()) {
            CRM_Report_Form_Instance::postProcess($this);
        } elseif ($this->_createNewButtonName == $this->controller->getButtonName() || $this->_outputMode == 'create_report' ) {
            $this->_createNew = TRUE;
            CRM_Report_Form_Instance::postProcess($this);
        }
    }
    /*
     * function to retrieve KID numbers for contribution
     */
    function retrieveKidNumber($contribution_id) {
        /*
         * return 'not found' if contribution_id is empty or non-numeric
         */
        $kidNumberFound = ts("not found");
        if (empty($contribution_id)) {
            return $kidNumberFound;
        }
        if (!is_numeric($contribution_id)) {
            return $kidNumberFound;
        }
        $selectKid = 
"SELECT kid_number FROM civicrm_kid_number WHERE entity = 'Contribution' AND entity_id = $contribution_id";
        $daoKid = CRM_Core_DAO::executeQuery($selectKid);
        if ($daoKid->fetch()) {
            if (isset($daoKid->kid_number) && !empty($daoKid->kid_number)) {
                $kidNumberFound = (string) $daoKid->kid_number;
            }
        }
        return $kidNumberFound;
    }
    /*
     * Function to set the 'send to bank' custom field to yes for the 
     * $rows array passed in
     */
    function setSendToBank($rows) {
        if (empty($rows) || !is_array($rows)) {
            return;            
        }
        /*
         * retrieve custom table and return if not found
         */
        $customGroupParams = array(
            'version'   =>  3,
            'title'     =>  "Nets Transactions"
        );
        $customGroupApi = civicrm_api('CustomGroup', 'Getsingle', $customGroupParams);
        if (civicrm_error($customGroupApi)) {
            return;
        }
        if (isset($customGroupApi['table_name'])) {
            $customGroupTable = $customGroupApi['table_name'];
        }
        if (isset($customGroupApi['id'])) {
            $customGroupId = $customGroupApi['id'];
        }
        if (!$customGroupTable || !$customGroupId) {
            return;
        }
        /*
         * retrieve custom field and return if not found
         */
        $customFieldParams = array(
            'version'           =>  3,
            'custom_group_id'   =>  $customGroupId,
            'label'             =>  "Sent to bank"
        );
        $customFieldApi = civicrm_api('CustomField', 'Getsingle', $customFieldParams);
        if (civicrm_error($customFieldApi)) {
            return;
        }
        if (isset($customFieldApi['column_name'])) {
            $customField = $customFieldApi['column_name'];
        }
        if (!$customField) {
            return;
        }
        /*
         * check if there is a record for the contribution
         */
        $selectNets = "SELECT entity_id FROM ".$customGroupTable;
        $daoNets = CRM_Core_DAO::executeQuery($selectNets);
        if ($daoNets->fetch()) {
            $setNets = 
"UPDATE ".$customGroupTable." SET ".$customField." = 1 WHERE entity_id = ".$daoNets->entity_id;
        } else {
            $setNets = "INSERT INTO ".$customGroupTable." SET ".$customField." = 1, entity_id = ".$daoNets->entity_id;
        }
        CRM_Core_DAO::executeQuery($setNets);
        return;
    }
    
}
