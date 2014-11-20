<?php
// This file declares a managed database record of type "ReportTemplate".
// The record will be automatically inserted, updated, or deleted from the
// database as appropriate. For more details, see "hook_civicrm_managed" at:
// http://wiki.civicrm.org/confluence/display/CRMDOC42/Hook+Reference
return array (
  0 => 
  array (
    'name' => 'CRM_Reports_Form_Report_DoubleGroupMembershipReport',
    'entity' => 'ReportTemplate',
    'params' => 
    array (
      'version' => 3,
      'label' => 'Double membership of group',
      'description' => 'Member of multiple groups at this moment',
      'class_name' => 'CRM_Reports_Form_Report_DoubleGroupMembershipReport',
      'report_url' => 'no.maf.reports/doublegroupmembershipreport',
      'component' => 'CiviContribute',
    ),
  ),
);
