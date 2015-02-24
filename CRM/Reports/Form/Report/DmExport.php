<?php

require_once 'CRM/Report/Form.php';

class CRM_Reports_Form_Report_DmExport extends CRM_Report_Form {

	protected $_addressField = FALSE;

	protected $_emailField = FALSE;

	protected $_summary = NULL;

	protected $_customGroupExtends = array();
	protected $_customGroupGroupBy = FALSE; 
	
	protected $aksjon_table;
	protected $aksjon_fields;
	
	protected $dm_activity_type_id = 60;
  
	function __construct() {
		$fields = array();
		$result = civicrm_api('CustomGroup', 'getsingle', array('version'=>3, 'name' => 'maf_norway_aksjon_import')); 
		if (!isset($result['is_error']) || !$result['is_error']) {
			$this->aksjon_table = $result['table_name'];
			if ($result['id']) {
				$result = civicrm_api('CustomField', 'get', array('version'=>3, 'custom_group_id' => $result['id']));
				if (isset($result['values']) && is_array($result['values'])) {					
					foreach($result['values'] as $field) {
						$fields[$field['name']] = $field;
					}
				}
			}
		}
		
		$this->aksjon_fields = $fields;
		
		$result = civicrm_api('OptionValue', 'getsingle', array('version'=>'3', 'name'=> 'Direct Mail (with KID)', 'option_group_id' => '2'));
		if (isset($result['value'])) {
			$this->dm_activity_type_id = $result['value'];
		}
	
		$this->_columns = array(
			'civicrm_contact' => array(
				'dao' => 'CRM_Contact_DAO_Contact',
				'filters' => array(
                    'activity' => array(
                        'title'         => ts('Activity Date'),
                        'operatorType'  => CRM_Report_Form::OP_DATE
					),
					'aksjon_id' => array(
                        'title'         => ts('Aksjon ID'),
                        'operatorType'  => CRM_Report_Form::OP_STRING,
						'type' => CRM_Utils_Type::T_STRING
					),
				),
			),
		);
		$this->_groupFilter = FALSE;
		$this->_tagFilter = FALSE;
		$this->_exposeContactID = FALSE;
		parent::__construct();
	}

	function preProcess() {
		$this->assign('reportTitle', ts('DM Export'));
		parent::preProcess();
	}

	function select() {
		$this->_select = "SELECT 
			contact.display_name, 
			address.street_address, 
			address.postal_code, 
			address.city, 
			country.name AS country, 
			at.target_contact_id as contact_id,  
			address.supplemental_address_1, 
			contact.nick_name, 
			'' as kid_number, 
			at.id as activity_target_id, 
			act.id as activity_id,
			".$this->aksjon_table.".".$this->aksjon_fields['aksjon_id']['column_name']." AS aksjon_id,
			'' AS total_contributions,
			'' AS total_amount,
			'' AS total_non_deductible_amount,
			'' as last_receive_date,
			'' as last_amount";
	}

	function from() {
		$this->_from = NULL;
        /*
         * FROM does not link into civicrm_kid_number, this is retrieved in the
         * alterDisplay function so we can check if there is one for the
         * contribution entity
         */
        $this->_from = 
			"FROM civicrm_activity_contact ac
			INNER JOIN civicrm_activity act ON act.id = ac.activity_id
			LEFT JOIN civicrm_contact contact ON ac.contact_id = contact.id
			LEFT JOIN civicrm_address address ON contact.id = address.contact_id AND address.is_primary = 1
			LEFT JOIN civicrm_country country ON address.country_id = country.id
			LEFT JOIN ".$this->aksjon_table." ON act.id = ".$this->aksjon_table.".entity_id";
    }

    function where() {
		$DM_with_kid_type_id = $this->dm_activity_type_id; //DM with KID activities
        $this->_where = NULL;
        $this->_where = "WHERE ac.record_type_id = 3 AND act.activity_type_id = '".$DM_with_kid_type_id."'";
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

		$op = isset($this->_submitValues['aksjon_id_op']) ? $this->_submitValues['aksjon_id_op'] : false;
		$value = isset($this->_submitValues['aksjon_id_value']) ? $this->_submitValues['aksjon_id_value'] : null;
		$min = isset($this->_submitValues['aksjon_id_min']) ? $this->_submitValues['aksjon_id_min'] : null;
		$max = isset($this->_submitValues['aksjon_id_max']) ? $this->_submitValues['aksjon_id_max'] : null;
		
		if ($op) {
			$clause = $this->whereClause($this->_columns['civicrm_contact']['filters']['aksjon_id'], $op, $value, $min, $max);
			if (!empty($clause)) {
				$this->_where .= " AND ".str_replace("contact_civireport.aksjon_id", $this->aksjon_table.".".$this->aksjon_fields['aksjon_id']['column_name'], $clause);
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
			'aksjon_id'                => array('title' => ts('Aksjon ID')),
			'total_contributions'       => array('title' => ts('Total contributions (this year)')),
			'total_amount'              => array('title' => ts('Total amount (this year)')),
			'total_non_deductible_amount'     => array('title' => ts('Total Non deductible amount (this year)')),
			'last_receive_date'              => array('title' => ts('Last contribution')),
			'last_amount'              => array('title' => ts('Last contribution amount')),
			'activity_target_id'        => array('no_display' => TRUE),
			'activity_id'               => array('no_display' => TRUE),
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
			if (array_key_exists('last_receive_date', $row) && array_key_exists('last_amount', $row)) {
				$last = $this->retrieveLastContribution($row['contact_id']);
				if (isset($last['receive_date'])) {
					$rows[$rowNum]['last_receive_date'] = $last['receive_date'];
				}
				if (isset($last['total_amount'])) {
					$rows[$rowNum]['last_amount'] = $last['total_amount'];
				}
            }
			
			if (array_key_exists('total_contributions', $row) && array_key_exists('total_amount', $row) && array_key_exists('total_non_deductible_amount', $row)) {
				$last = $this->retrieveTotalContributions($row['contact_id']);
				if (isset($last['total_contributions'])) {
					$rows[$rowNum]['total_contributions'] = $last['total_contributions'];
				}
				if (isset($last['total_amount'])) {
					$rows[$rowNum]['total_amount'] = $last['total_amount'];
				}
				if (isset($last['total_non_deductible_amount'])) {
					$rows[$rowNum]['total_non_deductible_amount'] = $last['total_non_deductible_amount'];
				}
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
		
        $selectKid = "SELECT kid_number FROM civicrm_kid_number WHERE entity = 'ActivityTarget' AND entity_id = $activity_id AND contact_id = $contact_id";
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
	
	function retrieveLastContribution($contact_id) {
		$last = array();
		$selectLast = "SELECT * FROM civicrm_contribution WHERE contact_id = '".$contact_id."' AND contribution_status_id = 1  ORDER BY receive_date DESC LIMIT 1";
		$daoContr = CRM_Core_DAO::executeQuery($selectLast);
		if ($daoContr->fetch()) {
			return $daoContr->toArray();
		}
		return $last;
	}
	
	function retrieveTotalContributions($contact_id) {
		$last = array();
		$selectLast = "SELECT 
			COUNT(tot_contr.id) AS total_contributions, 
			SUM(tot_contr.total_amount) AS total_amount, 
			SUM(tot_contr.non_deductible_amount) AS total_non_deductible_amount 
			FROM civicrm_contribution tot_contr 
			WHERE contact_id = '".$contact_id."'  AND tot_contr.contribution_status_id = 1 AND YEAR(tot_contr.receive_date) = YEAR(CURDATE()) 
			ORDER BY receive_date AND contribution_status_id = 1 DESC LIMIT 1";
		$daoContr = CRM_Core_DAO::executeQuery($selectLast);
		if ($daoContr->fetch()) {
			return $daoContr->toArray();
		}
		return $last;
	}
}
