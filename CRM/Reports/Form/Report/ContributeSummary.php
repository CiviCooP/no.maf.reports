<?php

require_once 'packages/OpenFlashChart/php-ofc-library/open-flash-chart.php';

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
                $config = CRM_Core_Config::Singleton();
                $symbol = $config->defaultCurrencySymbol;
                foreach ($rows as $key => $row) {
                    if (isset($row[$nets_transaction . '_' . $earmarking_field])) {
                        $value = $row[$nets_transaction . '_' . $earmarking_field];
                        $label = CRM_Core_DAO::singleValueQuery("SELECT label FROM civicrm_option_value where option_group_id = %1 and value = %2", array(
                            1 => array(self::$_earmarking_field['option_group_id'], 'Integer'),
                            2 => array($value, 'String'),
                        ));
                        $graphRows['multiValues'][0][$label] = $row['civicrm_contribution_total_amount_sum'];

                    }
                }

                $graphRows['barKeys'][0] = self::$_earmarking_field['label'];
                $graphRows['yname'] = "Amount ({$config->defaultCurrency})";
                $graphRows['xname'] = self::$_earmarking_field['label'];;
                $graphRows['values'] = $graphRows['multiValues'][0];
                $this->buildOfcChart($graphRows, $this->_params['charts']);
                $this->assign('chartType', $this->_params['charts']);
            } elseif (CRM_Utils_Array::value('group_id', $this->_params['group_bys'])) {
                $config = CRM_Core_Config::Singleton();
                $symbol = $config->defaultCurrencySymbol;
                foreach ($rows as $key => $row) {
                    if (isset($row['civicrm_contribution_donor_group_group_id'])) {
                        $value = $row['civicrm_contribution_donor_group_group_id'];
                        $label = CRM_Core_DAO::singleValueQuery("SELECT title from civicrm_group where id = %1", array(
                            1 => array($value, 'Integer'),
                        ));
                        $graphRows['multiValues'][0][$label] = $row['civicrm_contribution_total_amount_sum'];
                    }
                }

                $graphRows['barKeys'][0] = 'Donor group';
                $graphRows['tip'] = "#x_label#: $symbol #val#";
                $graphRows['yname'] = "Amount ({$config->defaultCurrency})";
                $graphRows['xname'] = 'Donor group';
                $graphRows['values'] = $graphRows['multiValues'][0];
                $this->buildOfcChart($graphRows, $this->_params['charts']);
                $this->assign('chartType', $this->_params['charts']);
            }
        }
    }

    function buildOfcChart(&$params, $chart) {
        if ($chart != 'barChart') {
            return CRM_Utils_OpenFlashChart::buildChart($params, $chart);
        }
        $openFlashChart = array();
        if ($chart && is_array($params) && !empty($params)) {
            // build the chart objects.
            $chartObj = $this->barChart($params);

            $openFlashChart = array();
            if ($chartObj) {
                // calculate chart size.
                $xSize = CRM_Utils_Array::value('xSize', $params, 400);
                $ySize = CRM_Utils_Array::value('ySize', $params, 300);
                if ($chart == 'barChart') {
                    $ySize = CRM_Utils_Array::value('ySize', $params, 250);
                    $xSize = 60 * count($params['values']);
                    //hack to show tooltip.
                    if ($xSize < 200) {
                        $xSize = (count($params['values']) > 1) ? 100 * count($params['values']) : 170;
                    }
                    elseif ($xSize > 600 && count($params['values']) > 1) {
                        $xSize = (count($params['values']) + 400 / count($params['values'])) * count($params['values']);
                    }
                }

                // generate unique id for this chart instance
                $uniqueId = md5(uniqid(rand(), TRUE));

                $openFlashChart["chart_{$uniqueId}"]['size'] = array('xSize' => $xSize, 'ySize' => $ySize);
                $openFlashChart["chart_{$uniqueId}"]['object'] = $chartObj;

                // assign chart data to template
                $template = CRM_Core_Smarty::singleton();
                $template->assign('uniqueId', $uniqueId);
                $template->assign("openFlashChartData", json_encode($openFlashChart));
            }
        }

        return $openFlashChart;
    }

    function &barChart(&$params) {
        $chart = NULL;
        if (empty($params)) {
            return $chart;
        }
        if (!CRM_Utils_Array::value('multiValues', $params)) {
            $params['multiValues'] = array($params['values']);
        }

        $values = CRM_Utils_Array::value('multiValues', $params);
        if (!is_array($values) || empty($values)) {
            return $chart;
        }

        // get the required data.
        $chartTitle = CRM_Utils_Array::value('legend', $params) ? $params['legend'] : ts('Bar Chart');

        $xValues = $yValues = array();
        $xValues = array_keys($values[0]);
        $yValues = array_values($values[0]);

        //set y axis parameters.
        $yMin = 0;

        // calculate max scale for graph.
        $yMax = ceil(max($yValues));
        if ($mod = $yMax % (str_pad(5, strlen($yMax) - 1, 0))) {
            $yMax += str_pad(5, strlen($yMax) - 1, 0) - $mod;
        }
        $ySteps = $yMax / 5;

        $bars = array();
        $config = CRM_Core_Config::singleton();
        $symbol = $config->defaultCurrencySymbol;
        foreach ($values as $barCount => $barVal) {
            $bars[$barCount] = new bar_glass();

            $yValues = array();
            foreach ($barVal as $label => $yVal) {
                // type casting is required for chart to render values correctly
                if (!$yVal instanceof bar_value) {
                    $yVal = (double)$yVal;
                    $yVal = new bar_value($yVal);
                    $yVal->set_tooltip($label.": $symbol #val#");
                    $yValues[] = $yVal;
                }

            }
            $bars[$barCount]->set_values($yValues);
            if ($barCount > 0) {
                // FIXME: for bars > 2, we'll need to come out with other colors
                $bars[$barCount]->colour( '#BF3B69');
            }

            if ($barKey = CRM_Utils_Array::value($barCount, CRM_Utils_Array::value('barKeys', $params))) {
                $bars[$barCount]->key($barKey,12);
            }

            // call user define function to handle on click event.
            if ($onClickFunName = CRM_Utils_Array::value('on_click_fun_name', $params)) {
                $bars[$barCount]->set_on_click($onClickFunName);
            }

            // get the currency to set in tooltip.
            $tooltip = CRM_Utils_Array::value('tip', $params, "$symbol #val#");
            $bars[$barCount]->set_tooltip($tooltip);
        }

        // create x axis label obj.
        $xLabels = new x_axis_labels();
        // set_labels function requires xValues array of string or x_axis_label
        // so type casting array values to string values
        array_walk($xValues, function(&$value, $index) {
            $value = trim(substr((string) $value, 0, 3));
        });
        $xLabels->set_labels($xValues);

        // set angle for labels.
        if ($xLabelAngle = CRM_Utils_Array::value('xLabelAngle', $params)) {
            $xLabels->rotate($xLabelAngle);
        }

        // create x axis obj.
        $xAxis = new x_axis();
        $xAxis->set_labels($xLabels);

        //create y axis and set range.
        $yAxis = new y_axis();
        $yAxis->set_range($yMin, $yMax, $ySteps);

        // create chart title obj.
        $title = new title($chartTitle);

        // create chart.
        $chart = new open_flash_chart();

        // add x axis w/ labels to chart.
        $chart->set_x_axis($xAxis);

        // add y axis values to chart.
        $chart->add_y_axis($yAxis);

        // set title to chart.
        $chart->set_title($title);

        // add bar element to chart.
        foreach ($bars as $bar) {
            $chart->add_element($bar);
        }

        // add x axis legend.
        if ($xName = CRM_Utils_Array::value('xname', $params)) {
            $xLegend = new x_legend($xName);
            $xLegend->set_style("{font-size: 13px; color:#000000; font-family: Verdana; text-align: center;}");
            $chart->set_x_legend($xLegend);
        }

        // add y axis legend.
        if ($yName = CRM_Utils_Array::value('yname', $params)) {
            $yLegend = new y_legend($yName);
            $yLegend->set_style("{font-size: 13px; color:#000000; font-family: Verdana; text-align: center;}");
            $chart->set_y_legend($yLegend);
        }

        return $chart;
    }

  function alterDisplay(&$rows) {
    // custom code to alter rows

    foreach ($rows as $rowNum => $row) {
      if (isset($row['civicrm_contribution_donor_group_group_id']) && !empty($row['civicrm_contribution_donor_group_group_id'])) {
        $groupTitle = civicrm_api3('Group', 'getvalue', array('id' => $row['civicrm_contribution_donor_group_group_id'], 'return' => 'title'));
        $rows[$rowNum]['civicrm_contribution_donor_group_group_id'] = $groupTitle;
      }

      // make count columns point to detail report
      if (CRM_Utils_Array::value('receive_date', $this->_params['group_bys']) &&
        CRM_Utils_Array::value('civicrm_contribution_receive_date_start', $row) &&
        CRM_Utils_Array::value('civicrm_contribution_receive_date_start', $row) &&
        CRM_Utils_Array::value('civicrm_contribution_receive_date_subtotal', $row)
      ) {

        $dateStart = CRM_Utils_Date::customFormat($row['civicrm_contribution_receive_date_start'], '%Y%m%d');
        $endDate   = new DateTime($dateStart);
        $dateEnd   = array();

        list($dateEnd['Y'], $dateEnd['M'], $dateEnd['d']) = explode(':', $endDate->format('Y:m:d'));

        switch (strtolower($this->_params['group_bys_freq']['receive_date'])) {
          case 'month':
            $dateEnd = date("Ymd", mktime(0, 0, 0, $dateEnd['M'] + 1,
              $dateEnd['d'] - 1, $dateEnd['Y']
            ));
            break;

          case 'year':
            $dateEnd = date("Ymd", mktime(0, 0, 0, $dateEnd['M'],
              $dateEnd['d'] - 1, $dateEnd['Y'] + 1
            ));
            break;

          case 'yearweek':
            $dateEnd = date("Ymd", mktime(0, 0, 0, $dateEnd['M'],
              $dateEnd['d'] + 6, $dateEnd['Y']
            ));
            break;

          case 'quarter':
            $dateEnd = date("Ymd", mktime(0, 0, 0, $dateEnd['M'] + 3,
              $dateEnd['d'] - 1, $dateEnd['Y']
            ));
            break;
        }
        $url = CRM_Report_Utils_Report::getNextUrl('contribute/detail',
          "reset=1&force=1&receive_date_from={$dateStart}&receive_date_to={$dateEnd}",
          $this->_absoluteUrl,
          $this->_id,
          $this->_drilldownReport
        );
        $rows[$rowNum]['civicrm_contribution_receive_date_start_link'] = $url;
        $rows[$rowNum]['civicrm_contribution_receive_date_start_hover'] = ts('List all contribution(s) for this date unit.');
      }

      // make subtotals look nicer
      if (array_key_exists('civicrm_contribution_receive_date_subtotal', $row) &&
        !$row['civicrm_contribution_receive_date_subtotal']
      ) {
        $this->fixSubTotalDisplay($rows[$rowNum], $this->_statFields);
      }

      // convert display name to links
      if (array_key_exists('civicrm_contact_sort_name', $row) &&
        array_key_exists('civicrm_contact_id', $row)
      ) {
        $url = CRM_Report_Utils_Report::getNextUrl('contribute/detail',
          'reset=1&force=1&id_op=eq&id_value=' . $row['civicrm_contact_id'],
          $this->_absoluteUrl, $this->_id, $this->_drilldownReport
        );
        $rows[$rowNum]['civicrm_contact_sort_name_link'] = $url;
        $rows[$rowNum]['civicrm_contact_sort_name_hover'] = ts("Lists detailed contribution(s) for this record.");
      }

      // If using campaigns, convert campaign_id to campaign title
      if (array_key_exists('civicrm_contribution_campaign_id', $row)) {
        if ($value = $row['civicrm_contribution_campaign_id']) {
          $rows[$rowNum]['civicrm_contribution_campaign_id'] = $this->activeCampaigns[$value];
        }
      }

      $this->alterDisplayAddressFields($row, $rows, $rowNum, 'contribute/detail', 'List all contribution(s) for this ');
    }
  }
}