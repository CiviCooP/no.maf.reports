<?php
// This file declares a managed database record of type "ReportTemplate".
// The record will be automatically inserted, updated, or deleted from the
// database as appropriate. For more details, see "hook_civicrm_managed" at:
// http://wiki.civicrm.org/confluence/display/CRMDOC42/Hook+Reference
return array (
  0 => 
  array (
    'name' => 'CRM_Reports_Form_Report_FailedOcrImport',
    'entity' => 'ReportTemplate',
    'params' => 
    array (
      'version' => 3,
      'label' => 'FailedOcrImport',
      'description' => 'FailedOcrImport (no.maf.reports)',
      'class_name' => 'CRM_Reports_Form_Report_FailedOcrImport',
      'report_url' => 'no.maf.reports/failedocrimport',
      'component' => 'CiviContribute',
    ),
  ),
);