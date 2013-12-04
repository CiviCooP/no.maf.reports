<?php

require_once 'CRM/Report/Form.php';

class CRM_Reports_Form_Report_DmExport extends CRM_Report_Form {

	protected $_addressField = FALSE;

	protected $_emailField = FALSE;

	protected $_summary = NULL;

	protected $_customGroupExtends = array();
	protected $_customGroupGroupBy = FALSE; 
  
	function __construct() {
		$this->_columns = array(
			'civicrm_contact' => array(
				'dao' => 'CRM_Contact_DAO_Contact',
				'fields' => array(
					'id' => array(
						'required' => TRUE,
						'default' => TRUE,
						'title' => ts('Contact ID'),
					),
					'display_name' => array(
						'title' => ts('Contact Name'),
						'required' => TRUE,
						'default' => TRUE,
						'no_repeat' => TRUE,
					),
					'nick_name' => array(
						'title' => ts('Nick Name'),
						'no_repeat' => TRUE,
						'default' => TRUE,
					),
				),
				'filters' => array(
                    'activity' => array(
                        'title'         => ts('Activity Date'),
                        'operatorType'  => CRM_Report_Form::OP_DATE)
					),
				),
				'grouping' => 'contact-fields',
			),
			'civicrm_activity_target' => array(
				'dao' => 'CRM_Activity_DAO_ActivityTarget',
			),
			'civicrm_activity' => array(
				'dao' => 'CRM_Activity_DAO_Activity',
			),
		);
		$this->_groupFilter = FALSE;
		$this->_tagFilter = FALSE;
		parent::__construct();
	}

	function preProcess() {
		$this->assign('reportTitle', ts('DM Export'));
		parent::preProcess();
	}

	function select() {
		$this->_select = "SELECT contact.display_name, address.street_address, address.postal_code, address.city, country.name AS country, at.contact_id as contact_id,  address.supplemental_address_1, contact.nick_name, '' as kid_number, at.id as activity_target_id, act.id as activity_id";
	}

	function from() {
		$this->_from = NULL;
        /*
         * FROM does not link into civicrm_kid_number, this is retrieved in the
         * alterDisplay function so we can check if there is one for the
         * contribution entity
         */
        $this->_from = 
			"FROM civicrm_activity act
			JOIN civicrm_activity_target at ON act.id = at.activity_id
			LEFT JOIN civicrm_contact contact ON at.contact_id = contact.id
			LEFT JOIN civicrm_address address ON contact.id = address.contact_id
			LEFT JOIN civicrm_country country ON address.country_id = country.id";
    }

    function where() {
		$DM_with_kid_type_id = 60; //DM with KID activities
        $this->_where = NULL;
        $this->_where = "WHERE at.activity_type_id = '".$DM_with_kid_type_id."'";
        if (isset($this->_submitValues['activity_from'])) {
            if (!empty($this->_submitValues['activity_from'])) {
                $this->_where .= " AND act.activity_date_time >= '".date("Y-m-d", strtotime($this->_submitValues['activity_from']))."'";
            }
        }
        if (isset($this->_submitValues['activity_to'])) {
            if (!empty($this->_submitValues['activity_to'])) {
                $this->_where .= " AND act.activity_date_time <= '".date("Y-m-d", strtotime($this->_submitValues['activity_to']))."'";
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
            'contact_id'                => array('title' => ts('Contact ID')),
			'display_name'              => array('title' => ts('Contact')),
			'nick_name'                 => array('title' => ts("Nickname")),
            'supplemental_address_1'    => array('title' => ts('Add. Address')),
            'street_address'            => array('title' => ts('Address')),
            'postal_code'               => array('title' => ts('Postal Code')),
            'city'                      => array('title' => ts('City')),
            'country'                   => array('title' => ts('Country')),
            'kid_number'                => array('title' => ts('KID number')),
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

            if (array_key_exists('kid_number', $row)) {
                $rows[$rowNum]['kid_number'] = $this->retrieveKidNumber($row['activity_target_id'], $row['activity_id'], $row['contact_id']);
            }
            // skip looking further in rows, if first row itself doesn't
            // have the column we need
            if ( !$entryFound ) {
                break;
            }
        }
    }
	
	/*function addFilters( ) {
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
    }*/
	
	    /*
     * function to retrieve KID numbers for contribution
     */
    function retrieveKidNumber($activity_target_id, $activity_id, $contact_id) {
        /*
         * return 'not found' if contribution_id is empty or non-numeric
         */
        $kidNumberFound = false;
        if (empty($activity_target_id)) {
            return ts("not found");
        }
        if (!is_numeric($activity_target_id)) {
            return ts("not found");
        }
		
        $selectKid = "SELECT kid_number FROM civicrm_kid_number WHERE entity = 'ActivityTarget' AND entity_id = $activity_target_id";
        $daoKid = CRM_Core_DAO::executeQuery($selectKid);
        if ($daoKid->fetch()) {
            if (isset($daoKid->kid_number) && !empty($daoKid->kid_number)) {
                $kidNumberFound = (string) $daoKid->kid_number;
            }
        }
		
		if ($kidNumberFound === false) {
			
			if (empty($activity_id)) {
				return ts("not found");
			}
			if (!is_numeric($activity_id)) {
				return ts("not found");
			}
			if (empty($contact_id)) {
				return ts("not found");
			}
			if (!is_numeric($contact_id)) {
				return ts("not found");
			}
			
			$selectKid = "SELECT kid_number FROM civicrm_kid_number WHERE entity = 'Activity' AND entity_id = $activity_id AND contact_id = $contact_id";
			$daoKid = CRM_Core_DAO::executeQuery($selectKid);
			if ($daoKid->fetch()) {
				if (isset($daoKid->kid_number) && !empty($daoKid->kid_number)) {
					$kidNumberFound = (string) $daoKid->kid_number;
				}
			}
		}
		
		if ($kidNumberFound === false) {
			$kidNumberFound = ts("not found");
		}
		
        return $kidNumberFound;
    }
}
