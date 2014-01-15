<?php

class CRM_Reports_Form_Report_GroupDonationsReport extends CRM_Report_Form {

  protected $_addressField = FALSE;

  protected $_emailField = FALSE;

  protected $_summary = NULL;

  protected $_customGroupGroupBy = FALSE; 
  
  function __construct() {

    $this->_groupFilter = FALSE;
    $this->_tagFilter = FALSE;
    parent::__construct();
  }

  function preProcess() {
    $this->assign('reportTitle', ts('Donations Report'));
    parent::preProcess();
  }

  function select() {
    $this->_select = "SELECT * ";
  }

  function from() {
    $this->_from = "`civicrm_contribution` `contr`";
  }

  function where() {    
    $this->_where = "WHERE ( 1 ) ";    
  }

  function groupBy() {
    $this->_groupBy = " ";
  }

  function orderBy() {
    $this->_orderBy = " ";
  }

  function postProcess() {

    $this->beginPostProcess();

    // get the acl clauses built before we assemble the query
    $sql = $this->buildQuery(TRUE);

    $rows = array();
    $this->buildRows($sql, $rows);

    $this->formatDisplay($rows);
    $this->doTemplateAssignment($rows);
    $this->endPostProcess($rows);
  }

  function alterDisplay(&$rows) {
    // custom code to alter rows
    
  }
}
