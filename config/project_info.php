<?php
// config/project_info.php
// Centralized Academic & Student Information Configuration
// Edit the placeholder values below to update student, guide, and college details across the entire system.

define('ACADEMIC_PROJECT_TITLE', 'Digital Investor Onboarding System using SSO, eKYC, eSign and Paperless Office Modules');
define('STUDENT_NAME', '[ENTER STUDENT NAME]');
define('REGISTER_NUMBER', '[ENTER REGISTER NUMBER]');
define('DEPARTMENT_NAME', 'CSE – Artificial Intelligence and Machine Learning');
define('COLLEGE_NAME', '[ENTER COLLEGE NAME]');
define('GUIDE_NAME', '[ENTER GUIDE NAME]');
define('TEAM_MEMBERS', '[ENTER TEAM MEMBERS]');
define('ACADEMIC_YEAR', '2026');

/**
 * Helper function to retrieve all academic metadata
 */
function getAcademicDetails() {
    return [
        'project_title'   => ACADEMIC_PROJECT_TITLE,
        'student_name'    => STUDENT_NAME,
        'register_number' => REGISTER_NUMBER,
        'department'      => DEPARTMENT_NAME,
        'college_name'    => COLLEGE_NAME,
        'guide_name'      => GUIDE_NAME,
        'team_members'    => TEAM_MEMBERS,
        'academic_year'   => ACADEMIC_YEAR
    ];
}
