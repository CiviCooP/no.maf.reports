<?php

class CRM_Reports_Form_Report_ContributeSummary extends CRM_Report_Form_Contribute_Summary
{

    protected static $_nets_transaction = false;

    protected static $_earmarking_field = false;

    function __construct()
    {
        self::getCustomFields();
        parent::__construct();

        $this->_columns['civicrm_contribution_donor_group'] = array(
            'fields' => array(
                'group_id' => array(
                    'title' => ts('Donor Group'),
                )
            ),
            'grouping' => 'contri-fields',
            'group_bys' => array(
                'group_id' => array(
                    'title' => ts('Donor group'),
                    'chart' => true,
                ),
            )
        );

        if (isset($this->_columns[self::$_nets_transaction['table_name']])) {
            $earmarking = 'custom_' . self::$_earmarking_field['id'];
            if (isset($this->_columns[self::$_nets_transaction['table_name']]['group_bys'][$earmarking])) {
                $this->_columns[self::$_nets_transaction['table_name']]['group_bys'][$earmarking]['chart'] = true;
            }
        }
    }

    function from()
    {
        parent::from();
        $this->_from .= " LEFT JOIN `civicrm_contribution_donorgroup` `{$this->_aliases['civicrm_contribution_donor_group']}` ON `{$this->_aliases['civicrm_contribution']}`.`id` = `{$this->_aliases['civicrm_contribution_donor_group']}`.`contribution_id`";
    }

    protected static function getCustomFields()
    {
        if (self::$_earmarking_field !== false) {
            return;
        }
        try {
            self::$_nets_transaction = civicrm_api3('CustomGroup', 'getsingle', array('name' => 'nets_transactions'));
            self::$_earmarking_field = civicrm_api3('CustomField', 'getsingle', array('custom_group_id' => self::$_nets_transaction['id'], 'name' => 'earmarking'));
        } Catch (Exception $e) {
            throw new Exception("Could not find Nets Transaction custom group and Earmarking field");
        }
    }

    static function formRule($fields, $files, $self)
    {
        $errors = $grouping = array();
        //check for searching combination of dispaly columns and
        //grouping criteria
        $ignoreFields = array('total_amount', 'sort_name');
        $errors = $self->customDataFormRule($fields, $ignoreFields);


        $earmarking = 'custom_' . self::$_earmarking_field['id'];
        if (!CRM_Utils_Array::value('receive_date', $fields['group_bys']) && !CRM_Utils_Array::value($earmarking, $fields['group_bys']) && !CRM_Utils_Array::value('group_id', $fields['group_bys'])) {
            if (CRM_Utils_Array::value('receive_date_relative', $fields) ||
                CRM_Utils_Date::isDate($fields['receive_date_from']) ||
                CRM_Utils_Date::isDate($fields['receive_date_to'])
            ) {
                $errors['receive_date_relative'] = ts("Do not use filter on Date if group by Receive Date or group by Earmarking is not used ");
            }
        }
        if (!CRM_Utils_Array::value('total_amount', $fields['fields'])) {
            foreach (array(
                         'total_count_value', 'total_sum_value', 'total_avg_value') as $val) {
                if (CRM_Utils_Array::value($val, $fields)) {
                    $errors[$val] = ts("Please select the Amount Statistics");
                }
            }
        }

        return $errors;
    }


    function buildChart(&$rows)
    {
        $graphRows = array();
        if (CRM_Utils_Array::value('charts', $this->_params)) {
            $nets_transaction = self::$_nets_transaction['table_name'];
            $earmarking_field = 'custom_' . self::$_earmarking_field['id'];
            if (CRM_Utils_Array::value('receive_date', $this->_params['group_bys'])) {
                $contrib = CRM_Utils_Array::value('total_amount', $this->_params['fields']) ? TRUE : FALSE;
                $softContrib = CRM_Utils_Array::value('soft_amount', $this->_params['fields']) ? TRUE : FALSE;

                foreach ($rows as $key => $row) {
                    if (isset($row['civicrm_contribution_receive_date_subtotal'])) {
                        $graphRows['receive_date'][] = $row['civicrm_contribution_receive_date_start'];
                        $graphRows[$this->_interval][] = $row['civicrm_contribution_receive_date_interval'];
                        if ($softContrib && $contrib) {
                            // both contri & soft contri stats are present
                            $graphRows['multiValue'][0][] = $row['civicrm_contribution_total_amount_sum'];
                            $graphRows['multiValue'][1][] = $row['civicrm_contribution_soft_soft_amount_sum'];
                        } else if ($softContrib) {
                            // only soft contributions
                            $graphRows['multiValue'][0][] = $row['civicrm_contribution_soft_soft_amount_sum'];
                        } else {
                            // only contributions
                            $graphRows['multiValue'][0][] = $row['civicrm_contribution_total_amount_sum'];
                        }
                    }
                }

                if ($softContrib && $contrib) {
                    $graphRows['barKeys'][0] = ts('Contributions');
                    $graphRows['barKeys'][1] = ts('Soft Credits');
                    $graphRows['legend'] = ts('Contributions and Soft Credits');
                } else if ($softContrib) {
                    $graphRows['legend'] = ts('Soft Credits');
                }

                // build the chart.
                $config = CRM_Core_Config::Singleton();
                $graphRows['yname'] = "Amount ({$config->defaultCurrency})";
                $graphRows['xname'] = $this->_interval;
                CRM_Utils_OpenFlashChart::chart($graphRows, $this->_params['charts'], $this->_interval);
                $this->assign('chartType', $this->_params['charts']);
            } elseif (CRM_Utils_Array::value($earmarking_field, $this->_params['group_bys'])) {
                foreach ($rows as $key => $row) {
                    if (isset($row[$nets_transaction . '_' . $earmarking_field])) {
                        $value = $row[$nets_transaction . '_' . $earmarking_field];
                        $label = CRM_Core_DAO::singleValueQuery("SELECT label FROM civicrm_option_value where option_group_id = %1 and value = %2", array(
                            1 => array(self::$_earmarking_field['option_group_id'], 'Integer'),
                            2 => array($value, 'String'),
                        ));
                        if ($this->_params['charts'] == 'barChart') {
                            $label = $value; //with barChart the labels arent displayed correctly
                        }
                        $graphRows['multiValue'][0][$label] = $row['civicrm_contribution_total_amount_sum'];

                    }
                }

                $graphRows['barKeys'][0] = self::$_earmarking_field['label'];

                // build the chart.
                $config = CRM_Core_Config::Singleton();
                $graphRows['yname'] = "Amount ({$config->defaultCurrency})";
                $graphRows['xname'] = self::$_earmarking_field['label'];;
                $graphRows['values'] = $graphRows['multiValue'][0];
                $graphRows['multiValues'][0] = $graphRows['multiValue'][0];
                CRM_Utils_OpenFlashChart::buildChart($graphRows, $this->_params['charts']);
                $this->assign('chartType', $this->_params['charts']);
            } elseif (CRM_Utils_Array::value('group_id', $this->_params['group_bys'])) {
                foreach ($rows as $key => $row) {
                    if (isset($row['civicrm_contribution_donor_group_group_id'])) {
                        $value = $row['civicrm_contribution_donor_group_group_id'];
                        $label = CRM_Core_DAO::singleValueQuery("SELECT title from civicrm_group where id = %1", array(
                            1 => array($value, 'Integer'),
                        ));
                        if ($this->_params['charts'] == 'barChart') {
                            $label = $value; //with barChart the labels arent displayed correctly
                        }
                        $graphRows['multiValues'][0][$label] = $row['civicrm_contribution_total_amount_sum'];
                    }
                }

                $graphRows['barKeys'][0] = 'Donor group';

                // build the chart.
                $config = CRM_Core_Config::Singleton();
                $graphRows['yname'] = "Amount ({$config->defaultCurrency})";
                $graphRows['xname'] = 'Donor group';
                $graphRows['values'] = $graphRows['multiValues'][0];
                CRM_Utils_OpenFlashChart::buildChart($graphRows, $this->_params['charts']);
                $this->assign('chartType', $this->_params['charts']);
            }
        }
    }
}