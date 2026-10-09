-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Sep 02, 2026 at 09:08 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `mit`
--

-- --------------------------------------------------------

--
-- Table structure for table `accounting_announcements`
--

CREATE TABLE `accounting_announcements` (
  `id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `message` text NOT NULL,
  `target_program` varchar(255) DEFAULT 'All',
  `target_year` varchar(50) DEFAULT 'All',
  `target_section` varchar(100) DEFAULT 'All',
  `created_by` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `accounting_fees`
--

CREATE TABLE `accounting_fees` (
  `id` int(11) NOT NULL,
  `fee_name` varchar(255) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `payment_rule` varchar(50) DEFAULT 'Full Payment Required',
  `linked_subject` varchar(100) DEFAULT 'None',
  `target_program` varchar(100) DEFAULT 'All',
  `target_year` varchar(50) DEFAULT 'All',
  `created_by` varchar(100) NOT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `active_curriculums`
--

CREATE TABLE `active_curriculums` (
  `id` int(11) NOT NULL,
  `program` varchar(255) NOT NULL,
  `curriculum_year` varchar(50) NOT NULL,
  `pdf_file` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `admissions`
--

CREATE TABLE `admissions` (
  `id` int(11) NOT NULL,
  `admission_number` varchar(100) DEFAULT NULL,
  `email` varchar(255) NOT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `gender` varchar(50) DEFAULT 'Unspecified',
  `dob` date DEFAULT NULL,
  `pob` varchar(255) DEFAULT NULL,
  `place_of_birth` varchar(255) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `program` varchar(255) DEFAULT NULL,
  `student_type` varchar(50) DEFAULT 'Freshman',
  `year_level` varchar(50) DEFAULT NULL,
  `fullname` varchar(255) DEFAULT NULL,
  `school_last_attended` varchar(255) DEFAULT NULL,
  `school_year_attended` varchar(50) DEFAULT NULL,
  `father_name` varchar(255) DEFAULT NULL,
  `father_occupation` varchar(255) DEFAULT NULL,
  `father_contact` varchar(50) DEFAULT NULL,
  `mother_name` varchar(255) DEFAULT NULL,
  `mother_occupation` varchar(255) DEFAULT NULL,
  `mother_contact` varchar(50) DEFAULT NULL,
  `emergency_contact_name` varchar(255) DEFAULT NULL,
  `emergency_contact_number` varchar(50) DEFAULT NULL,
  `influence_source` varchar(100) DEFAULT NULL,
  `uploaded_files` text DEFAULT NULL,
  `status` varchar(50) DEFAULT 'Pending',
  `temp_password` varchar(100) DEFAULT '123456',
  `custom_message` text DEFAULT NULL,
  `denial_reason` text DEFAULT NULL,
  `accepted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `last_name` varchar(100) NOT NULL,
  `first_name` varchar(100) NOT NULL,
  `middle_name` varchar(100) DEFAULT NULL,
  `account_request_pushed` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `admissions`
--

INSERT INTO `admissions` (`id`, `admission_number`, `email`, `phone`, `gender`, `dob`, `pob`, `place_of_birth`, `address`, `program`, `student_type`, `year_level`, `fullname`, `school_last_attended`, `school_year_attended`, `father_name`, `father_occupation`, `father_contact`, `mother_name`, `mother_occupation`, `mother_contact`, `emergency_contact_name`, `emergency_contact_number`, `influence_source`, `uploaded_files`, `status`, `temp_password`, `custom_message`, `denial_reason`, `accepted_at`, `created_at`, `last_name`, `first_name`, `middle_name`, `account_request_pushed`) VALUES
(12, 'ADM-2026-0002', 'GoInformationUp@gmail.com', '09683007037', 'Female', '2004-03-09', 'Lying ing', NULL, 'Cagbalete I, Mauban, Quezon, CALABARZON', 'Bachelor of Science in Information Systems', 'Freshman', '1st Year', NULL, 'Manuel S. Enverga Memorial School of Arts and Trades', '2025-2026', 'Balmond', 'NA', '09683007011', 'Joy', 'NA', '09683007012', 'Joy', '09683007012', 'Social Media', 'uploads/admissions/ADM-2026-0002_req_0_1786157984.jpg,uploads/admissions/ADM-2026-0002_req_1_1786157984.jpg,uploads/admissions/ADM-2026-0002_req_2_1786157984.jpg', 'Denied', '123456', NULL, 'google acc already used', NULL, '2026-08-08 02:59:44', 'Fay', 'Trisha K', 'Villamarzo', 0),
(13, 'ADM-2026-0003', 'FayTrishaK@gmail.com', '09683007037', 'Female', '2004-04-08', 'Lying ing', NULL, 'Dalipit East, Alitagtag, Batangas, CALABARZON', 'Bachelor of Science in Information Systems', 'Freshman', '1st Year', NULL, 'Manuel S. Enverga Memorial School of Arts and Trades', '2025-2026', 'Balmond', 'NA', '09683007011', 'Joy', 'NA', '09683007012', 'Joy', '09683007012', 'Social Media', 'uploads/admissions/ADM-2026-0003_req_0_1786158158.jpg,uploads/admissions/ADM-2026-0003_req_1_1786158158.jpg,uploads/admissions/ADM-2026-0003_req_2_1786158158.jpg', 'Accepted', '123456', 'Dear Fay, Trisha K Villamarzo,\r\n\r\nCongratulations! Your admission application at Lyceum de San Pablo has been officially ACCEPTED.\r\n\r\nBelow are your official examination details and credentials:\r\n--------------------------------------------------\r\nADMISSION NUMBER: ADM-2026-0003\r\nTEMP PASSWORD: nig2Nb7o\r\nEXAM PORTAL: http://localhost/LDSP_enrollment_system/student_exam_auth.php?adm_no=ADM-2026-0003\r\n--------------------------------------------------\r\n\r\nINSTRUCTIONS:\r\n1. Click the Exam Portal link above.\r\n2. Log in using your Admission Number and Temporary Password.\r\n3. Proceed to take your entrance examination.\r\n\r\nGood luck,\r\nLDSP Admissions Office', NULL, '2026-08-08 03:03:01', '2026-08-08 03:02:38', 'Fay', 'Trisha K', 'Villamarzo', 1),
(21, 'ADM-2026-0004', 'SilvaGrayQuag89@gmail.com', '09683007030', 'Male', '2004-03-05', 'Lying ing Center', NULL, 'Cagbalete II, Mauban, Quezon, CALABARZON', 'Bachelor of Early Childhood Education', 'Freshman', '1st Year', NULL, 'Manuel S. Enverga Memorial School of Arts and Trades', '2025-2026', 'Balmond', 'NA', '09683007012', 'Joy', 'NA', '09683007011', 'Balmond', '09683007012', 'Location', 'uploads/admissions/ADM-2026-0004_req_0_1786801262.jpg,uploads/admissions/ADM-2026-0004_req_1_1786801262.jpg', 'Accepted', '123456', 'Dear Silva, Gray quagmire,\r\n\r\nCongratulations! Your admission application at Lyceum de San Pablo has been officially ACCEPTED.\r\n\r\nBelow are your official examination details and credentials:\r\n--------------------------------------------------\r\nADMISSION NUMBER: ADM-2026-0004\r\nTEMP PASSWORD: Q3wimygZ\r\nEXAM PORTAL: http://localhost/LDSP_enrollment_system/student_exam_auth.php?adm_no=ADM-2026-0004\r\n--------------------------------------------------\r\n\r\nINSTRUCTIONS:\r\n1. Click the Exam Portal link above.\r\n2. Log in using your Admission Number and Temporary Password.\r\n3. Proceed to take your entrance examination.\r\n\r\nGood luck,\r\nLDSP Admissions Office', NULL, '2026-08-16 07:28:38', '2026-08-15 13:41:02', 'Silva', 'Gray', 'quagmire', 0),
(22, 'ADM-2026-0005', 'GraySky89@gmail.com', '09683007022', 'Female', '2004-01-15', 'Lying ing Center', NULL, 'Cagsiay I, Mauban, Quezon, CALABARZON', 'Bachelor of Early Childhood Education', 'Freshman', '1st Year', NULL, 'Manuel S. Enverga Memorial School of Arts and Trades', '2025-2026', 'Nolan', 'NA', '09683007022', 'Trisha', 'NA', '09683007031', 'Nolan', '09683007022', 'Parents', 'uploads/admissions/ADM-2026-0005_req_0_1786801908.jpg,uploads/admissions/ADM-2026-0005_req_1_1786801908.jpg', 'Accepted', '123456', 'Dear Gray, Skylar quagmire,\r\n\r\nCongratulations! Your admission application at Lyceum de San Pablo has been officially ACCEPTED.\r\n\r\nBelow are your official examination details and credentials:\r\n--------------------------------------------------\r\nADMISSION NUMBER: ADM-2026-0005\r\nTEMP PASSWORD: sMYcip13\r\nEXAM PORTAL: http://localhost/LDSP_enrollment_system/student_exam_auth.php?adm_no=ADM-2026-0005\r\n--------------------------------------------------\r\n\r\nINSTRUCTIONS:\r\n1. Click the Exam Portal link above.\r\n2. Log in using your Admission Number and Temporary Password.\r\n3. Proceed to take your entrance examination.\r\n\r\nGood luck,\r\nLDSP Admissions Office', NULL, '2026-08-15 13:59:18', '2026-08-15 13:51:48', 'Gray', 'Skylar', 'quagmire', 1),
(23, 'ADM-2026-0006', 'Vance89@gmail.com', '09683007034', 'Male', '2004-08-08', 'Lying ing Center', NULL, 'Cagbalete II, Mauban, Quezon, CALABARZON', 'Bachelor of Science in Psychology', 'Freshman', '1st Year', NULL, 'Manuel S. Enverga Memorial School of Arts and Trades', '2025-2026', 'Nolan', 'NA', '09683107012', 'Trisha', 'NA', '09689007037', 'Nolan', '09683107012', 'Friends', 'uploads/admissions/ADM-2026-0006_req_0_1786865955.docx,uploads/admissions/ADM-2026-0006_req_1_1786865955.pdf,uploads/admissions/ADM-2026-0006_req_2_1786865955.pdf', 'Accepted', '123456', 'Dear Vance, Nova Sterling,\r\n\r\nCongratulations! Your admission application at Lyceum de San Pablo has been officially ACCEPTED.\r\n\r\nBelow are your official examination details and credentials:\r\n--------------------------------------------------\r\nADMISSION NUMBER: ADM-2026-0006\r\nTEMP PASSWORD: pd3HeOkU\r\nEXAM PORTAL: http://localhost/LDSP_enrollment_system/student_exam_auth.php?adm_no=ADM-2026-0006\r\n--------------------------------------------------\r\n\r\nINSTRUCTIONS:\r\n1. Click the Exam Portal link above.\r\n2. Log in using your Admission Number and Temporary Password.\r\n3. Proceed to take your entrance examination.\r\n\r\nGood luck,\r\nLDSP Admissions Office', NULL, '2026-08-16 07:45:45', '2026-08-16 07:27:57', 'Vance', 'Nova', 'Sterling', 0),
(24, 'ADM-2026-0007', 'tashiyadeocampo@gmail.com', '09273854691', 'Female', '2004-09-10', 'Baguio City', NULL, 'Quirino Hill, Middle, City of Baguio, Benguet, CAR', 'Bachelor of Science in Information Systems', 'Regular', '4th Year', NULL, 'Saint Louis School Center ', '2023', 'Alberto Collera', 'Business Owner ', '09752143606', 'Liza Collera', 'Business Owner', '09752143660', 'Liza Collera', '09', 'Social Media', 'uploads/admissions/ADM-2026-0007_req_0_1788324401.png', 'Accepted', '123456', 'Dear Collera, Alyza De Ocampo,\n\nWelcome back! Your application for the upcoming term has been evaluated and officially ACCEPTED.\n\nYou are now cleared to log in to your secure Student Portal to submit your official Enrollment Request and verify your academic profile.\n\nIf you require assistance with your subjects, please reach out to your Program Head.\n\nRegards,\nLDSP Admissions Office', NULL, '2026-09-02 04:48:25', '2026-09-02 04:46:41', 'Collera', 'Alyza', 'De Ocampo', 1),
(25, 'ADM-2026-0008', 'JamesWarrenJ.Eviza89@gmail.com', '09683007039', 'Male', '2004-08-09', 'Lying ing center', NULL, 'Cagsiay I, Mauban, Quezon, CALABARZON', 'Bachelor of Early Childhood Education', 'Freshman', '1st Year', NULL, 'Manuel S. Enverga Memorial School of Arts and Trades', '2023', 'Nestor', 'Business Owner ', '09752143619', 'Melissa', 'Business Owner', '09755143660', 'Nestor', '09752143619', 'Friends', 'uploads/admissions/ADM-2026-0008_req_0_1788329473.jpg', 'Pending', '123456', NULL, NULL, NULL, '2026-09-02 06:11:13', 'Eviza', 'James Warren', 'Jabian', 0);

-- --------------------------------------------------------

--
-- Table structure for table `bank_accounts`
--

CREATE TABLE `bank_accounts` (
  `id` int(11) NOT NULL,
  `bank_name` varchar(100) NOT NULL,
  `account_name` varchar(100) NOT NULL,
  `account_number` varchar(50) NOT NULL,
  `theme_color` varchar(20) DEFAULT 'emerald',
  `payment_link` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `bank_accounts`
--

INSERT INTO `bank_accounts` (`id`, `bank_name`, `account_name`, `account_number`, `theme_color`, `payment_link`) VALUES
(1, 'BPI', 'LYCEUM DE SAN PABLO CITY INC', '002773-0363-17', 'red', NULL),
(2, 'EASTWEST BANK', 'LYCEUM DE SAN PABLO CITY INC', '200046-7617-14', 'purple', NULL),
(5, 'LAND BANK', 'N/A', 'N/A', 'emerald', 'https://www.lbp-eservices.com/egps/portal/index.jsp');

-- --------------------------------------------------------

--
-- Table structure for table `college_fees`
--

CREATE TABLE `college_fees` (
  `id` int(11) NOT NULL,
  `fee_code` varchar(50) NOT NULL,
  `fee_name` varchar(255) NOT NULL,
  `amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `semester` varchar(50) DEFAULT NULL,
  `fee_type` varchar(50) DEFAULT NULL,
  `created_by` varchar(100) DEFAULT NULL,
  `last_updated` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `enrollments`
--

CREATE TABLE `enrollments` (
  `id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `subject_id` int(11) NOT NULL,
  `grade` varchar(10) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `enrollments`
--

INSERT INTO `enrollments` (`id`, `student_id`, `subject_id`, `grade`) VALUES
(1, 81, 703, NULL),
(2, 79, 703, '1.25'),
(3, 81, 701, NULL),
(4, 79, 701, '1.25'),
(5, 81, 702, NULL),
(6, 79, 702, '1.50'),
(9, 81, 704, NULL),
(10, 79, 704, '1.75'),
(11, 81, 705, NULL),
(12, 79, 705, '1.25'),
(13, 81, 706, NULL),
(14, 79, 706, '1.25'),
(15, 81, 707, NULL),
(16, 79, 707, '1.50'),
(17, 81, 708, NULL),
(18, 79, 708, '1.50');

-- --------------------------------------------------------

--
-- Table structure for table `enrollment_requests`
--

CREATE TABLE `enrollment_requests` (
  `id` int(11) NOT NULL,
  `email` varchar(255) NOT NULL,
  `student_id` varchar(100) DEFAULT NULL,
  `program` varchar(255) DEFAULT NULL,
  `year_level` varchar(50) DEFAULT NULL,
  `gender` varchar(50) DEFAULT 'Unspecified',
  `learning_mode` varchar(50) DEFAULT NULL,
  `payment_proof` varchar(255) DEFAULT NULL,
  `program_head_status` varchar(50) DEFAULT 'Pending',
  `registrar_status` varchar(50) DEFAULT 'Pending',
  `accounting_status` varchar(50) DEFAULT 'Pending',
  `final_status` varchar(50) DEFAULT 'Pending',
  `assigned_section` varchar(100) DEFAULT NULL,
  `payment_or_number` varchar(100) DEFAULT NULL,
  `payment_notes` text DEFAULT NULL,
  `semester` varchar(50) DEFAULT NULL,
  `payment_amount` decimal(10,2) DEFAULT 0.00,
  `payment_balance` decimal(10,2) DEFAULT 0.00,
  `paid_through` varchar(100) DEFAULT NULL,
  `transaction_date` date DEFAULT NULL,
  `enrolled_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `payment_type` varchar(100) DEFAULT NULL,
  `student_status` varchar(50) DEFAULT 'Regular',
  `assessed_fee` decimal(10,2) DEFAULT NULL,
  `retake_fee` decimal(10,2) DEFAULT 0.00,
  `total_paid_accumulated` decimal(10,2) DEFAULT 0.00,
  `clearance_file` varchar(255) DEFAULT NULL,
  `classcard_file` varchar(255) DEFAULT NULL,
  `school_year` varchar(20) DEFAULT '2024-2025',
  `balance` decimal(10,2) DEFAULT 0.00
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `enrollment_requests`
--

INSERT INTO `enrollment_requests` (`id`, `email`, `student_id`, `program`, `year_level`, `gender`, `learning_mode`, `payment_proof`, `program_head_status`, `registrar_status`, `accounting_status`, `final_status`, `assigned_section`, `payment_or_number`, `payment_notes`, `semester`, `payment_amount`, `payment_balance`, `paid_through`, `transaction_date`, `enrolled_at`, `created_at`, `payment_type`, `student_status`, `assessed_fee`, `retake_fee`, `total_paid_accumulated`, `clearance_file`, `classcard_file`, `school_year`, `balance`) VALUES
(28, 'GraySky89@gmail.com', '2026-0004', 'Bachelor of Early Childhood Education', '1st Year', 'Male', 'Hybrid', 'uploads/payments/receipt_GraySky89gmailcom_1786804110.jpg', 'Pending', 'Pending', 'Pending', 'Enrolled', NULL, '12345678', NULL, '1st Semester', 8000.00, 0.00, 'BPI (x3-17)', '2026-08-15', '2026-08-15 14:32:17', '2026-08-15 14:21:33', 'Initial Enrollment Payment', 'Regular', 12000.00, 0.00, 8000.00, '', '', '2024-2025', 4000.00),
(29, 'FayTrishaK@gmail.com', '2026-0002', 'Bachelor of Early Childhood Education', '1st Year', 'Male', 'Hybrid', 'uploads/payments/receipt_FayTrishaKgmailcom_1788323307.png', 'Pending', 'Pending', 'Pending', 'Enrolled', NULL, '12345619', '9090', '1st Semester', 10000.00, 0.00, 'BPI', '2026-09-02', '2026-09-02 04:29:06', '2026-08-28 07:57:23', 'Payment for: Miscellaneous Fee (Full Payment)', 'Regular', NULL, 0.00, 10000.00, '', '', '2024-2025', 0.00),
(30, 'tashiyadeocampo@gmail.com', '23-00002', 'Bachelor of Science in Information Systems', '1st Year', 'Female', 'Hybrid', NULL, 'Pending', 'Pending', 'Pending', 'Pending Payment', NULL, NULL, NULL, '1st Semester', 0.00, 0.00, NULL, NULL, NULL, '2026-09-02 05:07:03', NULL, 'Regular', NULL, 0.00, 0.00, '', '', '2025-2026', 0.00);

-- --------------------------------------------------------

--
-- Table structure for table `entrance_exams`
--

CREATE TABLE `entrance_exams` (
  `id` int(11) NOT NULL,
  `exam_title` varchar(255) NOT NULL,
  `activation_time` datetime NOT NULL,
  `deadline_time` datetime NOT NULL,
  `duration_minutes` int(11) NOT NULL,
  `passing_score` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `entrance_exams`
--

INSERT INTO `entrance_exams` (`id`, `exam_title`, `activation_time`, `deadline_time`, `duration_minutes`, `passing_score`, `created_at`) VALUES
(1, '2026 Scholarship Exam', '2026-08-06 17:55:00', '2026-09-30 17:55:00', 60, 3, '2026-05-16 09:57:08');

-- --------------------------------------------------------

--
-- Table structure for table `exam_questions`
--

CREATE TABLE `exam_questions` (
  `id` int(11) NOT NULL,
  `exam_id` int(11) NOT NULL,
  `question_text` text NOT NULL,
  `question_image` varchar(255) DEFAULT NULL,
  `option_a` varchar(255) NOT NULL,
  `image_a` varchar(255) DEFAULT NULL,
  `option_b` varchar(255) NOT NULL,
  `image_b` varchar(255) DEFAULT NULL,
  `option_c` varchar(255) NOT NULL,
  `image_c` varchar(255) DEFAULT NULL,
  `option_d` varchar(255) NOT NULL,
  `image_d` varchar(255) DEFAULT NULL,
  `correct_option` char(1) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `sort_order` int(11) DEFAULT 0,
  `time_limit_seconds` int(11) DEFAULT 60
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `exam_questions`
--

INSERT INTO `exam_questions` (`id`, `exam_id`, `question_text`, `question_image`, `option_a`, `image_a`, `option_b`, `image_b`, `option_c`, `image_c`, `option_d`, `image_d`, `correct_option`, `created_at`, `sort_order`, `time_limit_seconds`) VALUES
(1, 1, '1+100=?', '', '1', '', '101', '', '3', '', '4', '', 'B', '2026-05-16 09:57:08', 2, 10),
(2, 1, '1+1=?', '', '1', '', '2', '', '3', '', '4', '', 'B', '2026-05-16 09:57:08', 3, 10),
(3, 1, '1+1=?', '', '1', '', '2', '', '3', '', '4', '', 'B', '2026-05-16 09:57:08', 1, 10);

-- --------------------------------------------------------

--
-- Table structure for table `exam_results`
--

CREATE TABLE `exam_results` (
  `id` int(11) NOT NULL,
  `student_id` varchar(100) NOT NULL,
  `exam_id` int(11) NOT NULL,
  `academic_year` varchar(50) NOT NULL,
  `score` int(11) NOT NULL,
  `total_questions` int(11) NOT NULL,
  `status` varchar(50) NOT NULL,
  `time_finished` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `exam_results`
--

INSERT INTO `exam_results` (`id`, `student_id`, `exam_id`, `academic_year`, `score`, `total_questions`, `status`, `time_finished`) VALUES
(8, 'ADM-2026-0001', 1, '', 3, 3, 'Passed', '2026-08-07 15:33:55'),
(9, 'ADM-2026-0003', 1, '', 3, 3, 'Passed', '2026-08-08 11:04:32'),
(10, 'ADM-2026-0005', 1, '', 3, 3, 'Passed', '2026-08-15 21:59:50'),
(11, 'ADM-2026-0007', 1, '', 3, 3, 'Passed', '2026-09-02 12:56:07');

-- --------------------------------------------------------

--
-- Table structure for table `portal_settings`
--

CREATE TABLE `portal_settings` (
  `setting_key` varchar(50) NOT NULL,
  `setting_value` text NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `portal_settings`
--

INSERT INTO `portal_settings` (`setting_key`, `setting_value`) VALUES
('academic_year', '2026-2027'),
('accepted_types', 'Freshman (New),Transferee (New),Returnee (Old),Irregular (Old),Regular (Old)'),
('active_school_year', '2025-2026'),
('active_semester', '1st Semester'),
('admission_status', 'Open'),
('announcement', 'Welcome to LDSP! Online enrollment is now officially ongoing. Please ensure all requirements are submitted upon approval.'),
('attachment_instructions', 'Please upload all required documents listed in the instructions.\r\n1.'),
('enrollment_status', 'Open'),
('portal_title', 'Admission Portal'),
('process_steps_count', '2'),
('requirements', '1. Form 138 (Original Report Card)\r\n2. PSA Birth Certificate (Photocopy)\r\n3. Certificate of Good Moral Character\r\n4. Two (2) pieces 2x2 ID Picture'),
('semester', '1st Semester'),
('step_1_desc', 'Fill out your personal and academic details carefully.'),
('step_1_title', 'Complete Applicant Data Entry'),
('step_2_desc', 'Attach clear copies of your required documents.'),
('step_2_title', 'Secure Requirement Upload'),
('step_3_desc', 'Wait for the official confirmation from the registrar.'),
('step_3_title', 'Registrar Verification & Approval');

-- --------------------------------------------------------

--
-- Table structure for table `programs`
--

CREATE TABLE `programs` (
  `program_id` int(11) NOT NULL,
  `program_name` varchar(255) NOT NULL,
  `is_archived` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `programs`
--

INSERT INTO `programs` (`program_id`, `program_name`, `is_archived`, `created_at`) VALUES
(4, 'Bachelor of Science In Accountancy', 0, '2026-05-16 09:49:13'),
(5, 'Bachelor of Science in Criminology', 0, '2026-05-16 09:49:17'),
(6, 'Bachelor of Science in Tourism Management', 0, '2026-05-16 10:28:02'),
(7, 'Bachelor of Early Childhood Education', 0, '2026-05-16 10:28:10'),
(8, 'Bachelor of Science in Psychology', 0, '2026-05-16 10:28:17'),
(9, 'Bachelor of Technology and Livelihood Education', 0, '2026-05-16 10:28:24'),
(10, 'Bachelor of Science in Information Systems', 0, '2026-05-16 10:28:32'),
(13, 'Bachelor of Science In Computer', 1, '2026-08-15 13:21:33');

-- --------------------------------------------------------

--
-- Table structure for table `program_defaults`
--

CREATE TABLE `program_defaults` (
  `program` varchar(255) NOT NULL,
  `active_year` varchar(50) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `program_defaults`
--

INSERT INTO `program_defaults` (`program`, `active_year`) VALUES
('Bachelor of Early Childhood Education', '2025-2026'),
('Bachelor of Science In Accountancy', '2024'),
('Bachelor of Science in Criminology', '2024'),
('Bachelor of Science in Information Systems', '2024'),
('Bachelor of Science in Psychology', '2024'),
('Bachelor of Science in Tourism Management', '2024-2025'),
('Bachelor of Technology & Livelihood Education', '2026'),
('Bachelor of Technology and Livelihood Education', '2024'),
('BS Information Technology', '2024');

-- --------------------------------------------------------

--
-- Table structure for table `program_heads`
--

CREATE TABLE `program_heads` (
  `id` int(11) NOT NULL,
  `program_name` varchar(100) NOT NULL,
  `head_name` varchar(150) NOT NULL,
  `employee_id` varchar(50) NOT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `prospectus`
--

CREATE TABLE `prospectus` (
  `id` int(11) NOT NULL,
  `program_id` int(11) DEFAULT NULL,
  `curriculum_year` varchar(50) NOT NULL DEFAULT '2024-2025',
  `year_level` varchar(50) NOT NULL,
  `semester` varchar(50) NOT NULL,
  `course_code` varchar(100) NOT NULL,
  `descriptive_title` varchar(255) DEFAULT NULL,
  `subject_title` varchar(255) NOT NULL,
  `units` int(11) NOT NULL,
  `prerequisite` varchar(100) DEFAULT 'None',
  `instructor` varchar(255) DEFAULT 'TBA',
  `teacher_id` int(11) DEFAULT NULL,
  `is_archived` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `subject_fee` decimal(10,2) DEFAULT 0.00,
  `unit_price` decimal(10,2) DEFAULT 0.00,
  `unit_cost` decimal(10,2) DEFAULT 0.00
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `prospectus`
--

INSERT INTO `prospectus` (`id`, `program_id`, `curriculum_year`, `year_level`, `semester`, `course_code`, `descriptive_title`, `subject_title`, `units`, `prerequisite`, `instructor`, `teacher_id`, `is_archived`, `created_at`, `subject_fee`, `unit_price`, `unit_cost`) VALUES
(553, 6, '2024-2025', '1st Year', '1st Semester', 'GE 1', 'Purposive Communication', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 13:56:19', 0.00, 500.00, 500.00),
(554, 6, '2024-2025', '1st Year', '1st Semester', 'GE 2', 'Readings in Philippine History', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 13:56:19', 0.00, 500.00, 500.00),
(555, 6, '2024-2025', '1st Year', '1st Semester', 'GE 3', 'Mathematics in the Modern World', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 13:56:19', 0.00, 500.00, 500.00),
(556, 6, '2024-2025', '1st Year', '1st Semester', 'THC1', 'Macro Perspective of Tourism and Hospitality', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 13:56:19', 0.00, 500.00, 500.00),
(557, 6, '2024-2025', '1st Year', '1st Semester', 'THC2', 'Risk Management as Applied to Safety, Security, and Sanitation', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 13:56:19', 0.00, 500.00, 500.00),
(558, 6, '2024-2025', '1st Year', '1st Semester', 'PE 112', 'Physical Fitness and Gymnastics', '', 2, 'None', 'TBA', NULL, 0, '2026-05-21 13:56:19', 0.00, 500.00, 500.00),
(560, 6, '2024-2025', '1st Year', '2nd Semester', 'THC3', 'Micro Perspective of Tourism and Hospitality', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 13:56:19', 0.00, 500.00, 500.00),
(561, 6, '2024-2025', '1st Year', '2nd Semester', 'THC4', 'Philippine Tourism, Geography, and Culture', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 13:56:19', 0.00, 500.00, 500.00),
(562, 6, '2024-2025', '1st Year', '2nd Semester', 'THC5', 'Quality Service Management in Tourism & Hospitality', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 13:56:19', 0.00, 500.00, 500.00),
(563, 6, '2024-2025', '1st Year', '2nd Semester', 'TPC1', 'Global Culture & Tourism Geography', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 13:56:19', 0.00, 500.00, 500.00),
(564, 6, '2024-2025', '1st Year', '2nd Semester', 'TPC2', 'Tour and Travel Management', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 13:56:19', 0.00, 500.00, 500.00),
(565, 6, '2024-2025', '1st Year', '2nd Semester', 'PE122', 'Philippine Dancing and Games', '', 2, 'None', 'TBA', NULL, 0, '2026-05-21 13:56:19', 0.00, 500.00, 500.00),
(566, 6, '2024-2025', '1st Year', '2nd Semester', 'NSTP 123', 'National Service Training Program 2', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 13:56:19', 1000.00, 500.00, 500.00),
(567, 6, '2024-2025', '2nd Year', '1st Semester', 'GE4', 'Understanding the Self', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 13:56:19', 0.00, 500.00, 500.00),
(568, 6, '2024-2025', '2nd Year', '1st Semester', 'GE5', 'Environmental Science', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 13:56:19', 0.00, 500.00, 500.00),
(569, 6, '2024-2025', '2nd Year', '1st Semester', 'TPC4', 'Applied Business Tools Technique (GDS with Lab)', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 13:56:19', 0.00, 500.00, 500.00),
(570, 6, '2024-2025', '2nd Year', '1st Semester', 'TPC3', 'Sustainable Tourism', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 13:56:19', 0.00, 500.00, 500.00),
(571, 6, '2024-2025', '2nd Year', '1st Semester', 'TME4', 'Elective 4 - Ecotourism Management', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 13:56:19', 0.00, 500.00, 500.00),
(572, 6, '2024-2025', '2nd Year', '1st Semester', 'TME1', 'Elective 1 - Recreational Management', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 13:56:19', 0.00, 500.00, 500.00),
(573, 6, '2024-2025', '2nd Year', '1st Semester', 'PE212', 'Indoor Games (Self-Defense)', '', 2, 'None', 'TBA', NULL, 0, '2026-05-21 13:56:19', 0.00, 500.00, 500.00),
(574, 6, '2024-2025', '2nd Year', '2nd Semester', 'GE6', 'Science, Technology and Society', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 13:56:19', 0.00, 500.00, 500.00),
(575, 6, '2024-2025', '2nd Year', '2nd Semester', 'TPC5', 'Tourism Policy Planning & Development', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 13:56:19', 0.00, 500.00, 500.00),
(576, 6, '2024-2025', '2nd Year', '2nd Semester', 'TPC6', 'Introduction to MICE (Meetings, Incentives, Conferences, & Exhibitions)', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 13:56:19', 0.00, 500.00, 500.00),
(577, 6, '2024-2025', '2nd Year', '2nd Semester', 'TFO8', 'Foreign Language 1', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 13:56:19', 0.00, 500.00, 500.00),
(578, 6, '2024-2025', '2nd Year', '2nd Semester', 'TME2', 'Elective 2 - Tour Guiding', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 13:56:19', 0.00, 500.00, 500.00),
(579, 6, '2024-2025', '2nd Year', '2nd Semester', 'PE222', 'Team Sports', '', 2, 'None', 'TBA', NULL, 0, '2026-05-21 13:56:19', 0.00, 500.00, 500.00),
(580, 6, '2024-2025', '3rd Year', '1st Semester', 'GE7', 'The Contemporary World', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 13:56:19', 0.00, 500.00, 500.00),
(581, 6, '2024-2025', '3rd Year', '1st Semester', 'TPC9', 'Foreign Language 2', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 13:56:19', 0.00, 500.00, 500.00),
(582, 6, '2024-2025', '3rd Year', '1st Semester', 'BME1', 'Operation Management in Tourism', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 13:56:19', 0.00, 500.00, 500.00),
(583, 6, '2024-2025', '3rd Year', '1st Semester', 'THC6', 'Professional Development & Applied Ethics', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 13:56:19', 0.00, 500.00, 500.00),
(584, 6, '2024-2025', '3rd Year', '1st Semester', 'THC7', 'Tourism & Hospitality Marketing', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 13:56:19', 0.00, 500.00, 500.00),
(585, 6, '2024-2025', '3rd Year', '1st Semester', 'TME3', 'Elective 3 - Destination Management & Marketing', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 13:56:19', 0.00, 500.00, 500.00),
(586, 6, '2024-2025', '3rd Year', '1st Semester', 'GELEC2', 'Philippine Pop Culture', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 13:56:19', 0.00, 500.00, 500.00),
(587, 6, '2024-2025', '3rd Year', '2nd Semester', 'THC8', 'Legal Aspects in Tourism and Hospitality', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 13:56:19', 0.00, 500.00, 500.00),
(588, 6, '2024-2025', '3rd Year', '2nd Semester', 'THC9', 'Multicultural Diversity in Workplace for the Tourism Professional', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 13:56:19', 0.00, 500.00, 500.00),
(589, 6, '2024-2025', '3rd Year', '2nd Semester', 'THC10', 'Entrepreneurship in Tourism 2', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 13:56:19', 0.00, 500.00, 500.00),
(590, 6, '2024-2025', '3rd Year', '2nd Semester', 'TPC7', 'Research in Tourism 2', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 13:56:19', 0.00, 500.00, 500.00),
(591, 6, '2024-2025', '3rd Year', '2nd Semester', 'TPC10', 'Transportation Management', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 13:56:19', 0.00, 500.00, 500.00),
(592, 6, '2024-2025', '3rd Year', '2nd Semester', 'BME2', 'Strategic Management & Total Quality Management', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 13:56:19', 0.00, 500.00, 500.00),
(593, 6, '2024-2025', '4th Year', '1st Semester', 'TME5', 'Elective 5 - Hospitality & Tourism Facilities Management and Design', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 13:56:19', 0.00, 500.00, 500.00),
(594, 6, '2024-2025', '4th Year', '1st Semester', 'RIZAL', 'Life and Works of Rizal', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 13:56:19', 0.00, 500.00, 500.00),
(595, 6, '2024-2025', '4th Year', '1st Semester', 'TPC11', 'Great Books', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 13:56:19', 0.00, 500.00, 500.00),
(596, 6, '2024-2025', '4th Year', '1st Semester', 'GELEC3', 'Research in Tourism II', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 13:56:19', 0.00, 500.00, 500.00),
(597, 6, '2024-2025', '4th Year', '1st Semester', 'TME6', 'Elective 6 - Hospitality and Tourism Business', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 13:56:19', 0.00, 500.00, 500.00),
(598, 6, '2024-2025', '4th Year', '1st Semester', 'GE8', 'Art Appreciation', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 13:56:19', 0.00, 500.00, 500.00),
(599, 6, '2024-2025', '4th Year', '2nd Semester', 'PRAC1M', 'Practicum (900 hrs)', '', 6, 'None', 'TBA', NULL, 0, '2026-05-21 13:56:19', 0.00, 500.00, 500.00),
(606, 6, '2024-2025', '1st Year', '1st Semester', 'NSTP 113', 'National Service Training Program 1', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 13:57:19', 1000.00, 500.00, 500.00),
(647, 9, '2024', '1st Year', '1st Semester', 'GE1', 'Understanding the Self', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:08:36', 0.00, 500.00, 500.00),
(648, 9, '2024', '1st Year', '1st Semester', 'GE2', 'Art Appreciation', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:08:36', 0.00, 500.00, 500.00),
(649, 9, '2024', '1st Year', '1st Semester', 'GE3', 'Purposive Communication', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:08:36', 0.00, 500.00, 500.00),
(650, 9, '2024', '1st Year', '1st Semester', 'HE1', 'Home Economics Literacy', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:08:36', 0.00, 500.00, 500.00),
(651, 9, '2024', '1st Year', '1st Semester', 'HE2', 'Family and Consumer Life Skills', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:08:36', 0.00, 500.00, 500.00),
(652, 9, '2024', '1st Year', '1st Semester', 'PROFED1', 'The Child and Adolescent Learner and Learning Principles', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:08:36', 0.00, 500.00, 500.00),
(653, 9, '2024', '1st Year', '1st Semester', 'PROFED2', 'The Teaching Professions', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:08:36', 0.00, 500.00, 500.00),
(654, 9, '2024', '1st Year', '1st Semester', 'PATHFIT1', 'Movement Competency and Training', '', 2, 'None', 'TBA', NULL, 0, '2026-05-21 14:08:36', 0.00, 500.00, 500.00),
(655, 9, '2024', '1st Year', '1st Semester', 'NSTP1', 'Literacy Training Service', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:08:36', 1000.00, 500.00, 500.00),
(656, 9, '2024', '1st Year', '2nd Semester', 'GE4', 'Mathematics in Modern World', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:08:36', 0.00, 500.00, 500.00),
(657, 9, '2024', '1st Year', '2nd Semester', 'GE5', 'Science Technology and Society', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:08:36', 0.00, 500.00, 500.00),
(658, 9, '2024', '1st Year', '2nd Semester', 'GE6', 'Reading in Philippine History', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:08:36', 0.00, 500.00, 500.00),
(659, 9, '2024', '1st Year', '2nd Semester', 'IA1', 'Introduction to Industrial Art 1', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:08:36', 0.00, 500.00, 500.00),
(660, 9, '2024', '1st Year', '2nd Semester', 'HM1', 'Household Resources Management', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:08:36', 0.00, 500.00, 500.00),
(661, 9, '2024', '1st Year', '2nd Semester', 'PROFED3', 'Foundation of Special Education', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:08:36', 0.00, 500.00, 500.00),
(662, 9, '2024', '1st Year', '2nd Semester', 'PROFED4', 'The Teacher and the Community, School Culture and Organizational Leadership', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:08:36', 0.00, 500.00, 500.00),
(663, 9, '2024', '1st Year', '2nd Semester', 'PATHFIT2', 'Fitness Exercises', '', 2, 'PATHFIT1', 'TBA', NULL, 0, '2026-05-21 14:08:36', 0.00, 500.00, 500.00),
(664, 9, '2024', '1st Year', '2nd Semester', 'NSTP2', 'Literacy Training Service', '', 3, 'NSTP1', 'TBA', NULL, 0, '2026-05-21 14:08:36', 1000.00, 500.00, 500.00),
(665, 9, '2024', '2nd Year', '1st Semester', 'GE7', 'Ethics', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:08:36', 0.00, 500.00, 500.00),
(666, 9, '2024', '2nd Year', '1st Semester', 'GE8', 'The Contemporary World', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:08:36', 0.00, 500.00, 500.00),
(667, 9, '2024', '2nd Year', '1st Semester', 'HM2', 'Consumer Education', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:08:36', 0.00, 500.00, 500.00),
(668, 9, '2024', '2nd Year', '1st Semester', 'IA2', 'Introduction to Industrial Art 2', '', 3, 'IA1', 'TBA', NULL, 0, '2026-05-21 14:08:36', 0.00, 500.00, 500.00),
(669, 9, '2024', '2nd Year', '1st Semester', 'FSAN2', 'Principles of Food Preparation', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:08:36', 0.00, 500.00, 500.00),
(670, 9, '2024', '2nd Year', '1st Semester', 'PCK1', 'Facilitating Learning-Centered Teaching the Learning-Centered Approaches with Emphasis on Training Methodology 1', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:08:36', 0.00, 500.00, 500.00),
(671, 9, '2024', '2nd Year', '1st Semester', 'PCK2', 'Assessment of Student Learning 1', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:08:36', 0.00, 500.00, 500.00),
(672, 9, '2024', '2nd Year', '1st Semester', 'PATHFIT3', 'Physical Activities Toward Health & Fitness', '', 2, 'PATHFIT2', 'TBA', NULL, 0, '2026-05-21 14:08:36', 0.00, 500.00, 500.00),
(673, 9, '2024', '2nd Year', '2nd Semester', 'RIZAL', 'The Life and Works of Rizal', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:08:36', 0.00, 500.00, 500.00),
(674, 9, '2024', '2nd Year', '2nd Semester', 'GELEC1', 'Environmental Science', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:08:36', 0.00, 500.00, 500.00),
(675, 9, '2024', '2nd Year', '2nd Semester', 'FSAN3', 'Fundamentals Of Food Technology', '', 3, 'FSAN2', 'TBA', NULL, 0, '2026-05-21 14:08:36', 0.00, 500.00, 500.00),
(676, 9, '2024', '2nd Year', '2nd Semester', 'ICT1', 'Introduction of ICT Specialization 1', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:08:36', 0.00, 500.00, 500.00),
(677, 9, '2024', '2nd Year', '2nd Semester', 'PCK3', 'Assessment In Learning 2 With Focus on Trainers Methodology I&II', '', 3, 'PCK2', 'TBA', NULL, 0, '2026-05-21 14:08:36', 0.00, 500.00, 500.00),
(678, 9, '2024', '2nd Year', '2nd Semester', 'PCK4', 'Technology Of Teaching and Learning I', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:08:36', 0.00, 500.00, 500.00),
(679, 9, '2024', '2nd Year', '2nd Semester', 'PCK5', 'Curriculum Development and Evaluation with Emphasis on The Trainers Methodology II', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:08:36', 0.00, 500.00, 500.00),
(680, 9, '2024', '2nd Year', '2nd Semester', 'AF1', 'Agri-Fishery 1', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:08:36', 0.00, 500.00, 500.00),
(681, 9, '2024', '2nd Year', '2nd Semester', 'PATHFIT4', 'Team Sports Games', '', 2, 'PATHFIT3', 'TBA', NULL, 0, '2026-05-21 14:08:36', 0.00, 500.00, 500.00),
(682, 9, '2024', '3rd Year', '1st Semester', 'GELEC2', 'Philippine Popular Culture', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:08:36', 0.00, 500.00, 500.00),
(683, 9, '2024', '3rd Year', '1st Semester', 'FLACD1', 'Child And Adolescent Development', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:08:36', 0.00, 500.00, 500.00),
(684, 9, '2024', '3rd Year', '1st Semester', 'FLACD2', 'Marriage And Family Relationship', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:08:36', 0.00, 500.00, 500.00),
(685, 9, '2024', '3rd Year', '1st Semester', 'ICT2', 'Introduction of ICT Specialization 2', '', 3, 'ICT1', 'TBA', NULL, 0, '2026-05-21 14:08:36', 0.00, 500.00, 500.00),
(686, 9, '2024', '3rd Year', '1st Semester', 'AF2', 'Ahr-Fishery Part II', '', 3, 'AF1', 'TBA', NULL, 0, '2026-05-21 14:08:36', 0.00, 500.00, 500.00),
(687, 9, '2024', '3rd Year', '1st Semester', 'ENTREP', 'Entrepreneurship', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:08:36', 0.00, 500.00, 500.00),
(688, 9, '2024', '3rd Year', '1st Semester', 'RESEARCH', 'Research 1 (Methods of Research)', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:08:36', 0.00, 500.00, 500.00),
(689, 9, '2024', '3rd Year', '1st Semester', 'PCK6', 'Building And Enhancing New Literacies Across the Curriculum with Emphasis on the 21st Century Skills', '', 3, 'PCK5', 'TBA', NULL, 0, '2026-05-21 14:08:36', 0.00, 500.00, 500.00),
(690, 9, '2024', '3rd Year', '2nd Semester', 'GELEC3', 'GREAT BOOKS', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:08:36', 0.00, 500.00, 500.00),
(691, 9, '2024', '3rd Year', '2nd Semester', 'CCAD1', 'Child And Adolescent Development', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:08:36', 0.00, 500.00, 500.00),
(692, 9, '2024', '3rd Year', '2nd Semester', 'CCAD2', 'Marriage And Family Relationship', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:08:36', 0.00, 500.00, 500.00),
(693, 9, '2024', '3rd Year', '2nd Semester', 'AAC1', 'Introduction of ICT Specialization 2', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:08:36', 0.00, 500.00, 500.00),
(694, 9, '2024', '3rd Year', '2nd Semester', 'AAC2', 'Ahr-Fishery Part II', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:08:36', 0.00, 500.00, 500.00),
(695, 9, '2024', '3rd Year', '2nd Semester', 'TR2', 'Entrepreneurship', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:08:36', 0.00, 500.00, 500.00),
(696, 9, '2024', '4th Year', '1st Semester', 'FS1', 'Field Study 1', '', 3, 'TPC9', 'TBA', NULL, 0, '2026-05-21 14:08:36', 0.00, 500.00, 500.00),
(697, 9, '2024', '4th Year', '1st Semester', 'FS2', 'Field Study 2 - Participation and Teaching Assistantship with Practice Teaching (On-Campus)', '', 5, 'None', 'TBA', NULL, 0, '2026-05-21 14:08:36', 0.00, 500.00, 500.00),
(698, 9, '2024', '4th Year', '1st Semester', 'CA1', 'Course Audit (General Education Review)', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:08:36', 0.00, 500.00, 500.00),
(699, 9, '2024', '4th Year', '2nd Semester', 'FS3', 'TEACHING INTERNSHIP (OFF-CAMPUS)', '', 6, 'GRADUATING', 'TBA', NULL, 0, '2026-05-21 14:08:36', 0.00, 500.00, 500.00),
(700, 9, '2024', '4th Year', '2nd Semester', 'CA2', 'Course Audit 2 (Professional Education Review)', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:08:36', 0.00, 500.00, 500.00),
(701, 7, '2025-2026', '1st Year', '1st Semester', 'PROFED1', 'The Child Adolescent and Learning Principles', '', 3, 'None', 'TBA', 84, 0, '2026-05-21 14:10:41', 0.00, 500.00, 500.00),
(702, 7, '2025-2026', '1st Year', '1st Semester', 'PROFED2', 'The Teaching Profession', '', 3, 'None', 'TBA', 84, 0, '2026-05-21 14:10:41', 0.00, 500.00, 500.00),
(703, 7, '2025-2026', '1st Year', '1st Semester', 'ECE2', 'Health And Nutrition Safety', '', 3, 'None', 'TBA', 84, 0, '2026-05-21 14:10:41', 0.00, 500.00, 500.00),
(704, 7, '2025-2026', '1st Year', '1st Semester', 'FIL1', 'Filipino', '', 3, 'None', 'TBA', 84, 0, '2026-05-21 14:10:41', 0.00, 500.00, 500.00),
(705, 7, '2025-2026', '1st Year', '1st Semester', 'GE1', 'Mathematics in the Modern World', '', 3, 'None', 'TBA', 84, 0, '2026-05-21 14:10:41', 0.00, 500.00, 500.00),
(706, 7, '2025-2026', '1st Year', '1st Semester', 'GE2', 'Purposive Communication', '', 3, 'None', 'TBA', 84, 0, '2026-05-21 14:10:41', 0.00, 500.00, 500.00),
(707, 7, '2025-2026', '1st Year', '1st Semester', 'PATHFIT1', 'Movement Competency and Training', '', 3, 'None', 'TBA', 84, 0, '2026-05-21 14:10:41', 0.00, 500.00, 500.00),
(708, 7, '2025-2026', '1st Year', '1st Semester', 'NSTP1', 'Literacy Training Service', '', 3, 'None', 'TBA', 84, 0, '2026-05-21 14:10:41', 1000.00, 500.00, 500.00),
(709, 7, '2025-2026', '1st Year', '2nd Semester', 'PROFED3', 'Foundation of Special and Inclusive Education', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:10:41', 0.00, 500.00, 500.00),
(710, 7, '2025-2026', '1st Year', '2nd Semester', 'PCK1', 'Assessment In Learning 1', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:10:41', 0.00, 500.00, 500.00),
(711, 7, '2025-2026', '1st Year', '2nd Semester', 'PCK2', 'Preparation of instructional materials for young children', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:10:41', 0.00, 500.00, 500.00),
(712, 7, '2025-2026', '1st Year', '2nd Semester', 'ECE1', 'Child And Development', '', 3, 'None', 'TBA', 84, 0, '2026-05-21 14:10:41', 0.00, 500.00, 500.00),
(713, 7, '2025-2026', '1st Year', '2nd Semester', 'ECE4', 'Play And Development Appropriate Practices in Early Childhood Education', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:10:41', 0.00, 500.00, 500.00),
(714, 7, '2025-2026', '1st Year', '2nd Semester', 'FIL2', 'Filipino 2', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:10:41', 0.00, 500.00, 500.00),
(715, 7, '2025-2026', '1st Year', '2nd Semester', 'PATHFIT2', 'Fitness Exercises', '', 2, 'PATHFIT1', 'TBA', NULL, 0, '2026-05-21 14:10:41', 0.00, 500.00, 500.00),
(716, 7, '2025-2026', '1st Year', '2nd Semester', 'NSTP2', 'Literacy Training Service', '', 3, 'NSTP1', 'TBA', NULL, 0, '2026-05-21 14:10:41', 1000.00, 500.00, 500.00),
(717, 7, '2025-2026', '2nd Year', '1st Semester', 'ECE7', 'Inclusive Education in Early Childhood Settings', '', 3, 'PROFED3', 'TBA', NULL, 0, '2026-05-21 14:10:41', 0.00, 500.00, 500.00),
(718, 7, '2025-2026', '2nd Year', '1st Semester', 'ECE21', 'Content And Pedagogy In The Tongue-based Multilingual', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:10:41', 0.00, 500.00, 500.00),
(719, 7, '2025-2026', '2nd Year', '1st Semester', 'ECE5', 'Creative Arts, Music, And Movement In Early Childhood Education', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:10:41', 0.00, 500.00, 500.00),
(720, 7, '2025-2026', '2nd Year', '1st Semester', 'PCK3', 'Technology For Teaching and Learning 1', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:10:41', 0.00, 500.00, 500.00),
(721, 7, '2025-2026', '2nd Year', '1st Semester', 'GE3', 'Art Appreciation', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:10:41', 0.00, 500.00, 500.00),
(722, 7, '2025-2026', '2nd Year', '1st Semester', 'PATHFIT3', 'Physical Activities Toward Health & Fitness', '', 2, 'PATHFIT2', 'TBA', NULL, 0, '2026-05-21 14:10:41', 0.00, 500.00, 500.00),
(723, 7, '2025-2026', '2nd Year', '2nd Semester', 'GE4', 'The contemporary world', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:10:41', 0.00, 500.00, 500.00),
(724, 7, '2025-2026', '2nd Year', '2nd Semester', 'GE8', 'Science, Technology & Society', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:10:41', 0.00, 500.00, 500.00),
(725, 7, '2025-2026', '2nd Year', '2nd Semester', 'ECE9', 'Assessment of children development and learning', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:10:41', 0.00, 500.00, 500.00),
(726, 7, '2025-2026', '2nd Year', '2nd Semester', 'ST1', 'Integrative teaching strategies', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:10:41', 0.00, 500.00, 500.00),
(727, 7, '2025-2026', '2nd Year', '2nd Semester', 'ECE6', 'Numeracy development', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:10:41', 0.00, 500.00, 500.00),
(728, 7, '2025-2026', '2nd Year', '2nd Semester', 'PCK4', 'The teacher and school curriculum', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:10:41', 0.00, 500.00, 500.00),
(729, 7, '2025-2026', '2nd Year', '2nd Semester', 'PCK5', 'Building and Enhancing New Literacies Across the Curriculum', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:10:41', 0.00, 500.00, 500.00),
(730, 7, '2025-2026', '2nd Year', '2nd Semester', 'PATHFIT4', 'Team Sports Games', '', 2, 'PATHFIT3', 'TBA', NULL, 0, '2026-05-21 14:10:41', 0.00, 500.00, 500.00),
(731, 7, '2025-2026', '3rd Year', '1st Semester', 'ECE14', 'Early Childhood Education Curriculum Models', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:10:41', 0.00, 500.00, 500.00),
(732, 7, '2025-2026', '3rd Year', '1st Semester', 'ECE17', 'Early Learning Environment', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:10:41', 0.00, 500.00, 500.00),
(733, 7, '2025-2026', '3rd Year', '1st Semester', 'ECE10', 'Literacy Development', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:10:41', 0.00, 500.00, 500.00),
(734, 7, '2025-2026', '3rd Year', '1st Semester', 'ECE11', 'Social Studies in Early Childhood Education', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:10:41', 0.00, 500.00, 500.00),
(735, 7, '2025-2026', '3rd Year', '1st Semester', 'ECE13', 'Science in Early', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:10:41', 0.00, 500.00, 500.00),
(736, 7, '2025-2026', '3rd Year', '1st Semester', 'ECE12', 'Teaching And Learning 2 (Utilization of Instructional Technology in Early Childhood)', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:10:41', 0.00, 500.00, 500.00),
(737, 7, '2025-2026', '3rd Year', '1st Semester', 'PROFED4', 'The Teacher and The Community, School Culture and Organizational Leadership', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:10:41', 0.00, 500.00, 500.00),
(738, 7, '2025-2026', '3rd Year', '1st Semester', 'ST2', 'Indigenous Creative Crafts', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:10:41', 0.00, 500.00, 500.00),
(739, 7, '2025-2026', '3rd Year', '1st Semester', 'LIT1', 'Literature', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:10:41', 0.00, 500.00, 500.00),
(740, 7, '2025-2026', '3rd Year', '2nd Semester', 'ECE15', 'Guiding Childrens Behavior and Moral Development', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:10:41', 0.00, 500.00, 500.00),
(741, 7, '2025-2026', '3rd Year', '2nd Semester', 'ECE16', 'Infant And Toddlers Programs', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:10:41', 0.00, 500.00, 500.00),
(742, 7, '2025-2026', '3rd Year', '2nd Semester', 'ECE18', 'Management Of Early Childhood Education Program', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:10:41', 0.00, 500.00, 500.00),
(743, 7, '2025-2026', '3rd Year', '2nd Semester', 'ECE20', 'Family, School, Community Partnerships', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:10:41', 0.00, 500.00, 500.00),
(744, 7, '2025-2026', '3rd Year', '2nd Semester', 'FS1', 'Observation of Teaching Learning in Actual School Environment', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:10:41', 0.00, 500.00, 500.00),
(745, 7, '2025-2026', '3rd Year', '2nd Semester', 'GE6', 'Ethics', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:10:41', 0.00, 500.00, 500.00),
(746, 7, '2025-2026', '3rd Year', '2nd Semester', 'LIT2', 'Literature', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:10:41', 0.00, 500.00, 500.00),
(747, 7, '2025-2026', '3rd Year', '2nd Semester', 'GE7', 'Life And Works of Rizal', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:10:41', 0.00, 500.00, 500.00),
(748, 7, '2025-2026', '4th Year', '1st Semester', 'ECE19', 'Research In Early Childhood Education', '', 3, 'TPC9', 'TBA', NULL, 0, '2026-05-21 14:10:41', 0.00, 500.00, 500.00),
(749, 7, '2025-2026', '4th Year', '1st Semester', 'FS2', 'Participation And Teaching Assistantship', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:10:41', 0.00, 500.00, 500.00),
(750, 7, '2025-2026', '4th Year', '2nd Semester', 'FS3', 'Teaching Internship (Off-Campus)', '', 18, 'GRADUATING', 'TBA', NULL, 0, '2026-05-21 14:10:41', 0.00, 500.00, 500.00),
(756, 5, '2024', '1st Year', '1st Semester', 'GE1', 'Understanding the Self', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:11:32', 0.00, 500.00, 500.00),
(757, 5, '2024', '1st Year', '1st Semester', 'GE2', 'Mathematics in the Modern World', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:11:32', 0.00, 500.00, 500.00),
(758, 5, '2024', '1st Year', '1st Semester', 'GE3', 'Purposive Communication', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:11:32', 0.00, 500.00, 500.00),
(759, 5, '2024', '1st Year', '1st Semester', 'GE4', 'Readings in Philippine History', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:11:32', 0.00, 500.00, 500.00),
(760, 5, '2024', '1st Year', '1st Semester', 'GEELEC1', 'Great Books', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:11:32', 0.00, 500.00, 500.00),
(761, 5, '2024', '1st Year', '1st Semester', 'CRIM1', 'Introduction to Criminology', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:11:32', 0.00, 500.00, 500.00),
(762, 5, '2024', '1st Year', '1st Semester', 'PE1', 'Fundamentals of Martial Arts', '', 2, 'None', 'TBA', NULL, 0, '2026-05-21 14:11:32', 0.00, 500.00, 500.00),
(763, 5, '2024', '1st Year', '1st Semester', 'MS113', 'Citizen Military Training', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:11:32', 0.00, 500.00, 500.00),
(764, 5, '2024', '1st Year', '2nd Semester', 'GE5', 'Contemporary World', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:11:32', 0.00, 500.00, 500.00),
(765, 5, '2024', '1st Year', '2nd Semester', 'GE6', 'Science, Technology & Society', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:11:32', 0.00, 500.00, 500.00),
(766, 5, '2024', '1st Year', '2nd Semester', 'GE7', 'Ethics', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:11:32', 0.00, 500.00, 500.00),
(767, 5, '2024', '1st Year', '2nd Semester', 'GE8', 'Art Appreciation', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:11:32', 0.00, 500.00, 500.00),
(768, 5, '2024', '1st Year', '2nd Semester', 'GEELEC2', 'Philippine Popular Culture', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:11:32', 0.00, 500.00, 500.00),
(769, 5, '2024', '1st Year', '2nd Semester', 'CLJ1', 'Introduction to Philippine Criminal Justice System', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:11:32', 0.00, 500.00, 500.00),
(770, 5, '2024', '1st Year', '2nd Semester', 'PE-2', 'Arms and Disarming Techniques', '', 2, 'None', 'TBA', NULL, 0, '2026-05-21 14:11:32', 0.00, 500.00, 500.00),
(771, 5, '2024', '1st Year', '2nd Semester', 'MS123', 'Citizen Military Training', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:11:32', 0.00, 500.00, 500.00),
(772, 5, '2024', '2nd Year', '1st Semester', 'GEELEC3', 'Gender and Society', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:11:32', 0.00, 500.00, 500.00),
(773, 5, '2024', '2nd Year', '1st Semester', 'PC', 'Life and Work of Rizal', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:11:32', 0.00, 500.00, 500.00),
(774, 5, '2024', '2nd Year', '1st Semester', 'CD1', 'Fundamentals of Investigation & Intelligence', '', 4, 'None', 'TBA', NULL, 0, '2026-05-21 14:11:32', 0.00, 500.00, 500.00),
(775, 5, '2024', '2nd Year', '1st Semester', 'LEA1', 'Law Enforcement Organization and Administration', '', 4, 'None', 'TBA', NULL, 0, '2026-05-21 14:11:32', 0.00, 500.00, 500.00),
(776, 5, '2024', '2nd Year', '1st Semester', 'LEA2', 'Comparative Models in Policing', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:11:32', 0.00, 500.00, 500.00),
(777, 5, '2024', '2nd Year', '1st Semester', 'CRIM2', 'Theories of Crime Causation', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:11:32', 0.00, 500.00, 500.00),
(778, 5, '2024', '2nd Year', '1st Semester', 'PE3', 'First Aid and Water Safety', '', 2, 'None', 'TBA', NULL, 0, '2026-05-21 14:11:32', 0.00, 500.00, 500.00),
(779, 5, '2024', '2nd Year', '2nd Semester', 'CA1', 'Institutional Corrections', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:11:32', 0.00, 500.00, 500.00),
(780, 5, '2024', '2nd Year', '2nd Semester', 'Forensic1', 'Forensic Photography', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:11:32', 0.00, 500.00, 500.00),
(781, 5, '2024', '2nd Year', '2nd Semester', 'CFLM-1', 'Character Formation, Nationalism & Patriotism', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:11:32', 0.00, 500.00, 500.00),
(782, 5, '2024', '2nd Year', '2nd Semester', 'CD12', 'Specialized Crime Investigation 1 with Legal Medicine', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:11:32', 0.00, 500.00, 500.00),
(783, 5, '2024', '2nd Year', '2nd Semester', 'Forensic2', 'Personal Identification Techniques', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:11:32', 0.00, 500.00, 500.00),
(784, 5, '2024', '2nd Year', '2nd Semester', 'AdGE', 'General Chemistry (Organic)', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:11:32', 0.00, 500.00, 500.00),
(785, 5, '2024', '2nd Year', '2nd Semester', 'CRIM3', 'Human Behavior & Victimology', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:11:32', 0.00, 500.00, 500.00),
(786, 5, '2024', '2nd Year', '2nd Semester', 'LEA3', 'Introduction to Industrial Security Concepts', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:11:32', 0.00, 500.00, 500.00),
(787, 5, '2024', '2nd Year', '2nd Semester', 'PE4', 'Fundamentals of Marksmanship', '', 2, 'None', 'TBA', NULL, 0, '2026-05-21 14:11:32', 0.00, 500.00, 500.00),
(788, 5, '2024', '3rd Year', '1st Semester', 'CLJ2', 'Human Rights Education', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:11:32', 0.00, 500.00, 500.00),
(789, 5, '2024', '3rd Year', '1st Semester', 'CFLM-2', 'Decision Making, Management and Administration', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:11:32', 0.00, 500.00, 500.00),
(790, 5, '2024', '3rd Year', '1st Semester', 'CD13', 'Specialized Crime Investigation', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:11:32', 0.00, 500.00, 500.00),
(791, 5, '2024', '3rd Year', '1st Semester', 'Forensic3', 'Forensic Chemistry and Toxicology', '', 5, 'None', 'TBA', NULL, 0, '2026-05-21 14:11:32', 0.00, 500.00, 500.00),
(792, 5, '2024', '3rd Year', '1st Semester', 'CLJ3', 'Criminal Law (Book 1)', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:11:32', 0.00, 500.00, 500.00),
(793, 5, '2024', '3rd Year', '1st Semester', 'LEA4', 'Law Enforcement Organizations and Planning with Crime Mapping', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:11:32', 0.00, 500.00, 500.00),
(794, 5, '2024', '3rd Year', '1st Semester', 'CD14', 'Traffic Management and Accident Investigation with Driving', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:11:32', 0.00, 500.00, 500.00),
(795, 5, '2024', '3rd Year', '1st Semester', 'CRIM4', 'Professional Conduct and Ethical Standards', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:11:32', 0.00, 500.00, 500.00),
(796, 5, '2024', '3rd Year', '2nd Semester', 'CA2', 'Non-Institutional Corrections', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:11:32', 0.00, 500.00, 500.00),
(797, 5, '2024', '3rd Year', '2nd Semester', 'CLJ4', 'Criminal Law (Book 2)', '', 4, 'None', 'TBA', NULL, 0, '2026-05-21 14:11:32', 0.00, 500.00, 500.00),
(798, 5, '2024', '3rd Year', '2nd Semester', 'FORENSIC', 'Lie Detection Techniques', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:11:32', 0.00, 500.00, 500.00),
(799, 5, '2024', '3rd Year', '2nd Semester', 'CRIM5', 'Juvenile Delinquency & Juvenile Justice System', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:11:32', 0.00, 500.00, 500.00),
(800, 5, '2024', '3rd Year', '2nd Semester', 'CD15', 'Technical English 1 (Investigative Report Writing and Presentation)', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:11:32', 0.00, 500.00, 500.00),
(801, 5, '2024', '3rd Year', '2nd Semester', 'CD16', 'Fire Protection and Arson Investigation', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:11:32', 0.00, 500.00, 500.00),
(802, 5, '2024', '3rd Year', '2nd Semester', 'CRIM6', 'Dispute Resolution and Crises Incidents Mgmt.', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:11:32', 0.00, 500.00, 500.00),
(803, 5, '2024', '4th Year', '1st Semester', 'CA3', 'Therapeutic Modalities', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:11:32', 0.00, 500.00, 500.00),
(804, 5, '2024', '4th Year', '1st Semester', 'CLJ5', 'Evidence', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:11:32', 0.00, 500.00, 500.00),
(805, 5, '2024', '4th Year', '1st Semester', 'CRIM7', 'Criminological Research 1 (Research Methods with Applied Statistics)', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:11:32', 0.00, 500.00, 500.00),
(806, 5, '2024', '4th Year', '1st Semester', 'CD17', 'Vice and Drug Education and Control', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:11:32', 0.00, 500.00, 500.00),
(807, 5, '2024', '4th Year', '1st Semester', 'Forensic6', 'Forensic Ballistics', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:11:32', 0.00, 500.00, 500.00),
(808, 5, '2024', '4th Year', '1st Semester', 'CLJ6', 'Criminal Procedures & Court Testimony', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:11:32', 0.00, 500.00, 500.00),
(809, 5, '2024', '4th Year', '1st Semester', 'CD18', 'Technical English 2 (Legal Forms)', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:11:32', 0.00, 500.00, 500.00),
(810, 5, '2024', '4th Year', '1st Semester', 'CD19', 'Introduction to Cybercrime and Environmental Law Protection', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:11:32', 0.00, 500.00, 500.00),
(811, 5, '2024', '4th Year', '2nd Semester', 'CRIMPRAC', 'Internship (On-the-Job-Training)', '', 6, 'None', 'TBA', NULL, 0, '2026-05-21 14:11:32', 0.00, 500.00, 500.00),
(812, 5, '2024', '4th Year', '2nd Semester', 'CRIM8', 'Criminological Research 2 (Thesis Writing and Writing Presentation)', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:11:32', 0.00, 500.00, 500.00),
(814, 4, '2024', '1st Year', '1st Semester', 'GE1', 'Understanding the Self', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:12:28', 0.00, 500.00, 500.00),
(815, 4, '2024', '1st Year', '1st Semester', 'GE2', 'Reading in Philippine History', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:12:28', 0.00, 500.00, 500.00),
(816, 4, '2024', '1st Year', '1st Semester', 'GE3', 'Contemporary World', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:12:28', 0.00, 500.00, 500.00),
(817, 4, '2024', '1st Year', '1st Semester', 'AE13', 'Financial Accounting & Reporting', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:12:28', 0.00, 500.00, 500.00),
(818, 4, '2024', '1st Year', '1st Semester', 'AE11', 'Managerial Economics', '', 3, 'None', 'TBA', 84, 0, '2026-05-21 14:12:28', 0.00, 500.00, 500.00),
(819, 4, '2024', '1st Year', '1st Semester', 'CBME1', 'Operation Management & TQM', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:12:28', 0.00, 500.00, 500.00),
(820, 4, '2024', '1st Year', '1st Semester', 'AE22', 'Cost Accounting and Control', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:12:28', 0.00, 500.00, 500.00),
(821, 4, '2024', '1st Year', '1st Semester', 'PE112', 'Physical Fitness & Gymnastics', '', 2, 'None', 'TBA', NULL, 0, '2026-05-21 14:12:28', 0.00, 500.00, 500.00),
(822, 4, '2024', '1st Year', '1st Semester', 'NSTP113', 'National Service Training Program 1', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:12:28', 1000.00, 500.00, 500.00),
(823, 4, '2024', '1st Year', '2nd Semester', 'GE4', 'Mathematics in the Modern World', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:12:28', 0.00, 500.00, 500.00),
(824, 4, '2024', '1st Year', '2nd Semester', 'GE5', 'Purposive Communication', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:12:28', 0.00, 500.00, 500.00),
(825, 4, '2024', '1st Year', '2nd Semester', 'AE12', 'Economics Development', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:12:28', 0.00, 500.00, 500.00),
(826, 4, '2024', '1st Year', '2nd Semester', 'AE1', 'Law on Obligation and Contracts', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:12:28', 0.00, 500.00, 500.00),
(827, 4, '2024', '1st Year', '2nd Semester', 'AE14', 'Conceptual Framework & Accounting Standards', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:12:28', 0.00, 500.00, 500.00),
(828, 4, '2024', '1st Year', '2nd Semester', 'AE4', 'Management Science', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:12:28', 0.00, 500.00, 500.00),
(829, 4, '2024', '1st Year', '2nd Semester', 'AE15', 'Intermediate Accounting 1', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:12:28', 0.00, 500.00, 500.00),
(830, 4, '2024', '1st Year', '2nd Semester', 'PE122', 'Rhythmic Activities', '', 2, 'None', 'TBA', NULL, 0, '2026-05-21 14:12:28', 0.00, 500.00, 500.00),
(831, 4, '2024', '1st Year', '2nd Semester', 'NSTP123', 'National Service Training Program 2', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:12:28', 1000.00, 500.00, 500.00),
(832, 4, '2024', '2nd Year', '1st Semester', 'GE6', 'Art Appreciation', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:12:28', 0.00, 500.00, 500.00),
(833, 4, '2024', '2nd Year', '1st Semester', 'GE7', 'Science Technology and Society', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:12:28', 0.00, 500.00, 500.00),
(834, 4, '2024', '2nd Year', '1st Semester', 'AE26', 'Income Taxation', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:12:28', 0.00, 500.00, 500.00),
(835, 4, '2024', '2nd Year', '1st Semester', 'AE16', 'Intermediate Accounting 2', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:12:28', 0.00, 500.00, 500.00),
(836, 4, '2024', '2nd Year', '1st Semester', 'AE23', 'Strategic Cost Management', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:12:28', 0.00, 500.00, 500.00),
(837, 4, '2024', '2nd Year', '1st Semester', 'AE2', 'Business Laws and Regulation', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:12:28', 0.00, 500.00, 500.00),
(838, 4, '2024', '2nd Year', '1st Semester', 'AE19', 'Financial Management', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:12:28', 0.00, 500.00, 500.00),
(839, 4, '2024', '2nd Year', '1st Semester', 'AE21', 'IT Application Tools in Business', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:12:28', 0.00, 500.00, 500.00),
(840, 4, '2024', '2nd Year', '1st Semester', 'PE212', 'Individual or Dual Sports', '', 2, 'None', 'TBA', NULL, 0, '2026-05-21 14:12:28', 0.00, 500.00, 500.00),
(841, 4, '2024', '2nd Year', '2nd Semester', 'GE8', 'Ethics', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:12:28', 0.00, 500.00, 500.00),
(842, 4, '2024', '2nd Year', '2nd Semester', 'GEELEC1', 'Business Logic', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:12:28', 0.00, 500.00, 500.00),
(843, 4, '2024', '2nd Year', '2nd Semester', 'AE25', 'Business Taxation', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:12:28', 0.00, 500.00, 500.00),
(844, 4, '2024', '2nd Year', '2nd Semester', 'AE3', 'Regulatory Framework & Logical Issues in Business', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:12:28', 0.00, 500.00, 500.00),
(845, 4, '2024', '2nd Year', '2nd Semester', 'AE17', 'Intermediate Accounting 3', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:12:28', 0.00, 500.00, 500.00),
(846, 4, '2024', '2nd Year', '2nd Semester', 'AE10', 'Governance, Business Ethics, Risk Management and Internal Control', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:12:28', 0.00, 500.00, 500.00),
(847, 4, '2024', '2nd Year', '2nd Semester', 'AE18', 'Financial Markets', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:12:28', 0.00, 500.00, 500.00),
(848, 4, '2024', '2nd Year', '2nd Semester', 'AE20', 'Accounting Information System', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:12:28', 0.00, 500.00, 500.00),
(849, 4, '2024', '2nd Year', '2nd Semester', 'PE222', 'Team Sports', '', 2, 'None', 'TBA', NULL, 0, '2026-05-21 14:12:28', 0.00, 500.00, 500.00),
(850, 4, '2024', '3rd Year', '1st Semester', 'RIZAL', 'Rizal\'s Life and Works', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:12:28', 0.00, 500.00, 500.00),
(851, 4, '2024', '3rd Year', '1st Semester', 'AE9', 'Statistical Analysis with Software Application', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:12:28', 0.00, 500.00, 500.00),
(852, 4, '2024', '3rd Year', '1st Semester', 'PrE1', 'Auditing and Assurance Principles', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:12:28', 0.00, 500.00, 500.00),
(853, 4, '2024', '3rd Year', '1st Semester', 'PrE2', 'Auditing and Assurance: Concepts & Application', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:12:28', 0.00, 500.00, 500.00),
(854, 4, '2024', '3rd Year', '1st Semester', 'PrE6', 'Accounting Special Transactions', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:12:28', 0.00, 500.00, 500.00),
(855, 4, '2024', '3rd Year', '1st Semester', 'PrE7', 'Accounting for Business Combinations', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:12:28', 0.00, 500.00, 500.00),
(856, 4, '2024', '3rd Year', '1st Semester', 'ELEC1', 'Professional Electives 1 - Updates in Financial Reporting Standards', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:12:28', 0.00, 500.00, 500.00),
(857, 4, '2024', '3rd Year', '2nd Semester', 'AE6', 'Accounting Research Methods', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:12:28', 0.00, 500.00, 500.00),
(858, 4, '2024', '3rd Year', '2nd Semester', 'AE5', 'International Business and Trade', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:12:28', 0.00, 500.00, 500.00),
(859, 4, '2024', '3rd Year', '2nd Semester', 'PrE8', 'Accounting for Government & Non-profit Organizations', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:12:28', 0.00, 500.00, 500.00),
(860, 4, '2024', '3rd Year', '2nd Semester', 'PrE3', 'Auditing & Assurance: Concepts and Application 2', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:12:28', 0.00, 500.00, 500.00),
(861, 4, '2024', '3rd Year', '2nd Semester', 'PrE4', 'Auditing & Assurance: Specialized Industries', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:12:28', 0.00, 500.00, 500.00),
(862, 4, '2024', '3rd Year', '2nd Semester', 'PrE5', 'Auditing in CIS Environment', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:12:28', 0.00, 500.00, 500.00),
(863, 4, '2024', '3rd Year', '2nd Semester', 'ELEC2', 'Professional Elective 2 - Human Behavior in Organization', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:12:28', 0.00, 500.00, 500.00),
(864, 4, '2024', '4th Year', '1st Semester', 'GEELEC2', 'Social Science & Philosophy', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:12:28', 0.00, 500.00, 500.00),
(865, 4, '2024', '4th Year', '1st Semester', 'GEELEC3', 'Arts & Humanities', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:12:28', 0.00, 500.00, 500.00),
(866, 4, '2024', '4th Year', '1st Semester', 'CBME2', 'Strategic Management', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:12:28', 0.00, 500.00, 500.00),
(867, 4, '2024', '4th Year', '1st Semester', 'AE24', 'Strategic Business Analysis', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:12:28', 0.00, 500.00, 500.00),
(868, 4, '2024', '4th Year', '1st Semester', 'ELEC3', 'Professional Elective 3 - Operation Auditing', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:12:28', 0.00, 500.00, 500.00),
(869, 4, '2024', '4th Year', '1st Semester', 'ELEC4', 'Professional Elective 4 - Valuation Concepts and Methods', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:12:28', 0.00, 500.00, 500.00),
(870, 4, '2024', '4th Year', '2nd Semester', 'AE7', 'Accounting Internship', '', 6, 'None', 'TBA', NULL, 0, '2026-05-21 14:12:28', 0.00, 500.00, 500.00),
(871, 4, '2024', '4th Year', '2nd Semester', 'AE8', 'Accountancy Research', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:12:28', 0.00, 500.00, 500.00),
(872, 8, '2024', '1st Year', '1st Semester', 'GE1', 'Understanding the Self', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:13:26', 0.00, 500.00, 500.00),
(873, 8, '2024', '1st Year', '1st Semester', 'GE2', 'Reading in the Philippine History', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:13:26', 0.00, 500.00, 500.00),
(874, 8, '2024', '1st Year', '1st Semester', 'GE3', 'Purposive Communication', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:13:26', 0.00, 500.00, 500.00),
(875, 8, '2024', '1st Year', '1st Semester', 'GE4', 'Mathematics in the Modern World', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:13:26', 0.00, 500.00, 500.00),
(876, 8, '2024', '1st Year', '1st Semester', 'PSY1', 'Introduction to Psychology', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:13:26', 0.00, 500.00, 500.00),
(877, 8, '2024', '1st Year', '1st Semester', 'GENELEC1', 'Philippine Popular Culture', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:13:26', 0.00, 500.00, 500.00),
(878, 8, '2024', '1st Year', '1st Semester', 'PATHFIT1', 'Movement Competency and Training', '', 2, 'None', 'TBA', NULL, 0, '2026-05-21 14:13:26', 0.00, 500.00, 500.00),
(879, 8, '2024', '1st Year', '1st Semester', 'NSTP1', 'Community Welfare Training Service 1', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:13:26', 1000.00, 500.00, 500.00),
(880, 8, '2024', '1st Year', '2nd Semester', 'GE5', 'Art Appreciation', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:13:26', 0.00, 500.00, 500.00),
(881, 8, '2024', '1st Year', '2nd Semester', 'GE6', 'Ethics', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:13:26', 0.00, 500.00, 500.00),
(882, 8, '2024', '1st Year', '2nd Semester', 'PSY2', 'Psychological Statistics', '', 5, 'None', 'TBA', NULL, 0, '2026-05-21 14:13:26', 0.00, 500.00, 500.00),
(883, 8, '2024', '1st Year', '2nd Semester', 'PSY3', 'Developmental Psychology', '', 3, 'PSY1', 'TBA', NULL, 0, '2026-05-21 14:13:26', 0.00, 500.00, 500.00),
(884, 8, '2024', '1st Year', '2nd Semester', 'NS1', 'Anatomy and Physiology', '', 5, 'None', 'TBA', NULL, 0, '2026-05-21 14:13:26', 0.00, 500.00, 500.00),
(885, 8, '2024', '1st Year', '2nd Semester', 'GENELEC2', 'Gender & Society', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:13:26', 0.00, 500.00, 500.00),
(886, 8, '2024', '1st Year', '2nd Semester', 'PATHFIT2', 'Exercise-Based Fitness Activities', '', 2, 'PATHFIT1', 'TBA', NULL, 0, '2026-05-21 14:13:26', 0.00, 500.00, 500.00),
(887, 8, '2024', '1st Year', '2nd Semester', 'NSTP2', 'Community Welfare Training Service 2', '', 3, 'NSTP1', 'TBA', NULL, 0, '2026-05-21 14:13:26', 1000.00, 500.00, 500.00),
(888, 8, '2024', '2nd Year', '1st Semester', 'GE7', 'Science, Technology, & Society', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:13:26', 0.00, 500.00, 500.00),
(889, 8, '2024', '2nd Year', '1st Semester', 'GE8', 'The Contemporary World', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:13:26', 0.00, 500.00, 500.00),
(890, 8, '2024', '2nd Year', '1st Semester', 'GENELEC3', 'Great Books', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:13:26', 0.00, 500.00, 500.00),
(891, 8, '2024', '2nd Year', '1st Semester', 'PSY4', 'Experimental Psychology', '', 5, 'PSY1,PSY2', 'TBA', NULL, 0, '2026-05-21 14:13:26', 0.00, 500.00, 500.00),
(892, 8, '2024', '2nd Year', '1st Semester', 'PSY5', 'Cognitive Psychology', '', 3, 'PSY1', 'TBA', NULL, 0, '2026-05-21 14:13:26', 0.00, 500.00, 500.00),
(893, 8, '2024', '2nd Year', '1st Semester', 'PSY6', 'Theories of Personality', '', 3, 'PSY1', 'TBA', NULL, 0, '2026-05-21 14:13:26', 0.00, 500.00, 500.00),
(894, 8, '2024', '2nd Year', '1st Semester', 'NS2', 'General Organic Chemistry', '', 5, 'PSY1', 'TBA', NULL, 0, '2026-05-21 14:13:26', 0.00, 500.00, 500.00),
(895, 8, '2024', '2nd Year', '1st Semester', 'PATHFIT3', 'Dancing, Martial Arts, Group Exercise, Outdoor/Adventure Activities', '', 2, 'PATHFIT1,PATHFIT2', 'TBA', NULL, 0, '2026-05-21 14:13:26', 0.00, 500.00, 500.00),
(896, 8, '2024', '2nd Year', '2nd Semester', 'RIZAL', 'Rizal Life & Works', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:13:26', 0.00, 500.00, 500.00),
(897, 8, '2024', '2nd Year', '2nd Semester', 'PSY7', 'Bio/Psychological Psychology', '', 3, 'PSY1', 'TBA', NULL, 0, '2026-05-21 14:13:26', 0.00, 500.00, 500.00),
(898, 8, '2024', '2nd Year', '2nd Semester', 'PSY8', 'Field Method in Psychology', '', 5, 'PSY1,PSY2', 'TBA', NULL, 0, '2026-05-21 14:13:26', 0.00, 500.00, 500.00),
(899, 8, '2024', '2nd Year', '2nd Semester', 'PSY9', 'Psychological Assessment', '', 5, 'PSY1,PSY6', 'TBA', NULL, 0, '2026-05-21 14:13:26', 0.00, 500.00, 500.00),
(900, 8, '2024', '2nd Year', '2nd Semester', 'NS3', 'General Inorganic Chemistry', '', 5, 'None', 'TBA', NULL, 0, '2026-05-21 14:13:26', 0.00, 500.00, 500.00),
(901, 8, '2024', '2nd Year', '2nd Semester', 'PATHFIT4', 'Dancing, Martial Arts, Group Exercise, Outdoor/Adventure', '', 2, 'PATHFIT1,PATHFIT2', 'TBA', NULL, 0, '2026-05-21 14:13:26', 0.00, 500.00, 500.00),
(902, 8, '2024', '3rd Year', '1st Semester', 'PSY10', 'Social Psychology', '', 3, 'PSY1', 'TBA', NULL, 0, '2026-05-21 14:13:26', 0.00, 500.00, 500.00),
(903, 8, '2024', '3rd Year', '1st Semester', 'PSY11', 'Abnormal Psychology', '', 3, 'PSY1,PSY6', 'TBA', NULL, 0, '2026-05-21 14:13:26', 0.00, 500.00, 500.00);
INSERT INTO `prospectus` (`id`, `program_id`, `curriculum_year`, `year_level`, `semester`, `course_code`, `descriptive_title`, `subject_title`, `units`, `prerequisite`, `instructor`, `teacher_id`, `is_archived`, `created_at`, `subject_fee`, `unit_price`, `unit_cost`) VALUES
(904, 8, '2024', '3rd Year', '1st Semester', 'PSY12', 'Filipino Psychology', '', 3, 'PSY1', 'TBA', NULL, 0, '2026-05-21 14:13:26', 0.00, 500.00, 500.00),
(905, 8, '2024', '3rd Year', '1st Semester', 'PSY13', 'Disaster and Mental Health', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:13:26', 0.00, 500.00, 500.00),
(906, 8, '2024', '3rd Year', '1st Semester', 'NS4', 'College Physics', '', 5, 'None', 'TBA', NULL, 0, '2026-05-21 14:13:26', 0.00, 500.00, 500.00),
(907, 8, '2024', '3rd Year', '2nd Semester', 'PSY14', 'Industrial-Organizational Psychology', '', 3, 'PSY1', 'TBA', NULL, 0, '2026-05-21 14:13:26', 0.00, 500.00, 500.00),
(908, 8, '2024', '3rd Year', '2nd Semester', 'PSY15', 'Research in Psychology 1', '', 3, 'PSY4,PSY8', 'TBA', NULL, 0, '2026-05-21 14:13:26', 0.00, 500.00, 500.00),
(909, 8, '2024', '3rd Year', '2nd Semester', 'PSY16', 'Introduction to Counseling', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:13:26', 0.00, 500.00, 500.00),
(910, 8, '2024', '3rd Year', '2nd Semester', 'PSY17', 'Introduction to Clinical Psychology', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:13:26', 0.00, 500.00, 500.00),
(911, 8, '2024', '3rd Year', '2nd Semester', 'PSY18', 'Group Dynamics', '', 3, 'None', 'TBA', NULL, 0, '2026-05-21 14:13:26', 0.00, 500.00, 500.00),
(912, 8, '2024', '4th Year', '1st Semester', 'PSY21', 'Practicum in Psychology (200 hours)', '', 3, '4th Year Standing', 'TBA', NULL, 0, '2026-05-21 14:13:26', 0.00, 500.00, 500.00),
(913, 8, '2024', '4th Year', '2nd Semester', 'PSY19', 'Research in Psychology 2', '', 3, 'PSY16', 'TBA', NULL, 0, '2026-05-21 14:13:26', 0.00, 500.00, 500.00),
(914, 8, '2024', '4th Year', '2nd Semester', 'PSY20', 'Synthesis in Psychology', '', 3, '4th Year Standing', 'TBA', NULL, 0, '2026-05-21 14:13:26', 0.00, 500.00, 500.00);

-- --------------------------------------------------------

--
-- Table structure for table `prospectus_update_requests`
--

CREATE TABLE `prospectus_update_requests` (
  `id` int(11) NOT NULL,
  `request_type` varchar(50) NOT NULL,
  `target_program` varchar(100) DEFAULT NULL,
  `curriculum_year` varchar(50) DEFAULT NULL,
  `year_level` varchar(50) DEFAULT NULL,
  `semester` varchar(50) DEFAULT NULL,
  `course_code` varchar(50) NOT NULL,
  `new_title` varchar(255) DEFAULT NULL,
  `new_units` int(11) DEFAULT NULL,
  `new_prerequisite` varchar(100) DEFAULT NULL,
  `new_fee` decimal(10,2) DEFAULT NULL,
  `reason` text NOT NULL,
  `requested_by` varchar(100) NOT NULL,
  `status` varchar(50) DEFAULT 'Pending',
  `admin_feedback` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `registrar_announcements`
--

CREATE TABLE `registrar_announcements` (
  `id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `message` text NOT NULL,
  `target_program` varchar(100) DEFAULT 'All',
  `target_year` varchar(50) DEFAULT 'All',
  `target_section` varchar(50) DEFAULT 'All',
  `created_by` varchar(100) NOT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `registrar_fees`
--

CREATE TABLE `registrar_fees` (
  `id` int(11) NOT NULL,
  `fee_name` varchar(255) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `payment_rule` varchar(50) DEFAULT 'Full Payment Required',
  `linked_subject` varchar(100) DEFAULT 'None',
  `target_program` varchar(100) DEFAULT 'All',
  `target_year` varchar(50) DEFAULT 'All',
  `created_by` varchar(100) NOT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `first_payment` decimal(10,2) DEFAULT 0.00,
  `second_payment` decimal(10,2) DEFAULT 0.00
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `registrar_fees`
--

INSERT INTO `registrar_fees` (`id`, `fee_name`, `amount`, `payment_rule`, `linked_subject`, `target_program`, `target_year`, `created_by`, `created_at`, `first_payment`, `second_payment`) VALUES
(7, 'Miscellaneous Fee', 10000.00, 'Installments Allowed', 'None', 'All', 'All', 'James Warren J. Eviza', '2026-05-21 20:36:00', 8000.00, 2000.00),
(8, 'Tuition Fee', 24000.00, 'Installments Allowed', 'None', 'All', 'All', 'James Warren J. Eviza', '2026-05-21 20:43:11', 0.00, 0.00),
(9, 'DRIVING', 2000.00, 'Full Payment Required', 'None', 'BS Criminology', '3rd Year', 'James Warren J. Eviza', '2026-05-23 22:09:41', 0.00, 0.00),
(10, 'ROTC 1', 1000.00, 'Full Payment Required', 'None', 'BS Criminology', '1st Year', 'James Warren J. Eviza', '2026-05-23 22:12:26', 0.00, 0.00),
(11, 'ROTC 2', 1000.00, 'Full Payment Required', 'None', 'BS Criminology', '1st Year', 'James Warren J. Eviza', '2026-05-23 22:12:38', 0.00, 0.00),
(12, 'MARKSMANSHIP', 2500.00, 'Full Payment Required', 'None', 'BS Criminology', '2nd Year', 'James Warren J. Eviza', '2026-05-23 22:13:56', 0.00, 0.00),
(13, 'Internship Fee', 2500.00, 'Full Payment Required', 'None', 'All', '4th Year', 'James Warren J. Eviza', '2026-05-23 22:14:43', 0.00, 0.00);

-- --------------------------------------------------------

--
-- Table structure for table `registrar_memos`
--

CREATE TABLE `registrar_memos` (
  `id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `message` text NOT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `section_settings`
--

CREATE TABLE `section_settings` (
  `id` int(11) NOT NULL,
  `semester` varchar(50) NOT NULL,
  `program` varchar(100) NOT NULL,
  `year_level` varchar(50) NOT NULL,
  `section_name` varchar(50) NOT NULL,
  `student_limit` int(11) DEFAULT 40
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `section_settings`
--

INSERT INTO `section_settings` (`id`, `semester`, `program`, `year_level`, `section_name`, `student_limit`) VALUES
(1, '1st Semester', 'Bachelor of Science in Information Systems', '1st Year', 'A', 40),
(3, '1st Semester', 'Bachelor of Science in Criminology', '1st Year', 'A', 60);

-- --------------------------------------------------------

--
-- Table structure for table `source_of_info_list`
--

CREATE TABLE `source_of_info_list` (
  `id` int(11) NOT NULL,
  `source_name` varchar(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `source_of_info_list`
--

INSERT INTO `source_of_info_list` (`id`, `source_name`) VALUES
(5, 'Faculty'),
(4, 'Flyers / Tarpaulin'),
(2, 'Friends'),
(3, 'Location'),
(1, 'Parents'),
(9, 'Referral'),
(8, 'Relative Studying Here'),
(7, 'School Counselor / Teacher'),
(6, 'Social Media (Facebook / Google)');

-- --------------------------------------------------------

--
-- Table structure for table `student_grades`
--

CREATE TABLE `student_grades` (
  `id` int(11) NOT NULL,
  `student_id` varchar(100) NOT NULL,
  `subject_code` varchar(50) NOT NULL,
  `subject_description` varchar(255) NOT NULL,
  `units` decimal(4,2) DEFAULT 0.00,
  `teacher_name` varchar(255) NOT NULL,
  `grade` decimal(5,2) DEFAULT 0.00,
  `date_graded` datetime DEFAULT current_timestamp(),
  `school_year` varchar(20) DEFAULT NULL,
  `semester` varchar(50) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `system_settings`
--

CREATE TABLE `system_settings` (
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text NOT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `system_settings`
--

INSERT INTO `system_settings` (`setting_key`, `setting_value`, `updated_at`) VALUES
('enrollment_requirements', 'Original Form 138 (SF9) / Learner\'s Progress Report Card\r\nOriginal Certificate of Good Moral Character\r\nPhotocopy of PSA Birth Certificate\r\nTwo (2) copies of 2x2 ID Picture with white background\r\n', '2026-09-02 04:57:27'),
('login_bg_file', '', '2026-08-21 15:39:19'),
('login_bg_h', '100', '2026-08-07 04:30:31'),
('login_bg_scale', '1', '2026-08-07 06:46:15'),
('login_bg_type', 'none', '2026-08-21 15:39:19'),
('login_bg_w', '100', '2026-08-07 04:30:31'),
('login_bg_x', '0', '2026-08-07 06:37:27'),
('login_bg_y', '0', '2026-08-07 06:37:27'),
('login_card_scale', '1.15', '2026-08-15 15:01:59'),
('login_card_x', '38.21', '2026-09-02 04:13:01'),
('login_card_y', '13.88', '2026-09-02 04:13:01');

-- --------------------------------------------------------

--
-- Table structure for table `tbl_fees`
--

CREATE TABLE `tbl_fees` (
  `id` int(11) NOT NULL,
  `fee_name` varchar(100) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `target_student` varchar(50) DEFAULT 'All',
  `description` text DEFAULT NULL,
  `status` varchar(20) DEFAULT 'Active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `teaching_applications`
--

CREATE TABLE `teaching_applications` (
  `id` int(11) NOT NULL,
  `teacher_id` int(11) NOT NULL,
  `subject_id` int(11) NOT NULL,
  `semester` varchar(50) NOT NULL,
  `status` varchar(20) DEFAULT 'Pending',
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `teaching_applications`
--

INSERT INTO `teaching_applications` (`id`, `teacher_id`, `subject_id`, `semester`, `status`, `created_at`) VALUES
(3, 84, 712, '2nd Semester', 'Approved', '2026-08-22 11:14:17'),
(4, 84, 703, '1st Semester', 'Approved', '2026-08-22 11:15:38'),
(7, 84, 818, '1st Semester', 'Approved', '2026-08-24 18:38:57'),
(9, 84, 704, '1st Semester', 'Approved', '2026-08-24 18:50:01'),
(10, 84, 705, '1st Semester', 'Approved', '2026-08-24 18:50:02'),
(11, 84, 706, '1st Semester', 'Approved', '2026-08-24 18:50:03'),
(12, 84, 708, '1st Semester', 'Approved', '2026-08-24 18:50:03'),
(13, 84, 707, '1st Semester', 'Approved', '2026-08-24 18:50:03'),
(14, 84, 701, '1st Semester', 'Approved', '2026-08-24 18:50:04'),
(15, 84, 702, '1st Semester', 'Approved', '2026-08-24 18:50:04');

-- --------------------------------------------------------

--
-- Table structure for table `transaction_history`
--

CREATE TABLE `transaction_history` (
  `id` int(11) NOT NULL,
  `student_email` varchar(100) NOT NULL,
  `or_number` varchar(100) NOT NULL,
  `payment_type` varchar(255) DEFAULT NULL,
  `amount` decimal(10,2) NOT NULL,
  `transaction_date` date DEFAULT NULL,
  `program` varchar(100) DEFAULT NULL,
  `approved_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `transaction_history`
--

INSERT INTO `transaction_history` (`id`, `student_email`, `or_number`, `payment_type`, `amount`, `transaction_date`, `program`, `approved_at`) VALUES
(2, 'GraySky89@gmail.com', '12345678', 'Initial Enrollment Payment', 8000.00, '2026-08-15', 'Bachelor of Early Childhood Education', '2026-08-15 22:32:09');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `name` varchar(255) DEFAULT NULL,
  `last_name` varchar(100) DEFAULT NULL,
  `first_name` varchar(100) DEFAULT NULL,
  `middle_name` varchar(100) DEFAULT NULL,
  `email` varchar(255) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` varchar(50) NOT NULL DEFAULT 'student',
  `program` varchar(255) DEFAULT NULL,
  `student_id` varchar(100) DEFAULT NULL,
  `employee_id` varchar(100) DEFAULT NULL,
  `is_archived` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `gender` varchar(20) DEFAULT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `dob` date DEFAULT NULL,
  `pob` varchar(255) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `school_last_attended` varchar(255) DEFAULT NULL,
  `school_year_attended` varchar(50) DEFAULT NULL,
  `father_name` varchar(255) DEFAULT NULL,
  `father_occupation` varchar(255) DEFAULT NULL,
  `father_contact` varchar(50) DEFAULT NULL,
  `mother_name` varchar(255) DEFAULT NULL,
  `mother_occupation` varchar(255) DEFAULT NULL,
  `mother_contact` varchar(50) DEFAULT NULL,
  `emergency_contact_name` varchar(255) DEFAULT NULL,
  `emergency_contact_number` varchar(50) DEFAULT NULL,
  `learning_mode` varchar(20) DEFAULT NULL,
  `year_level` varchar(20) DEFAULT NULL,
  `admission_type` varchar(50) DEFAULT 'Freshman',
  `source_of_info` varchar(100) DEFAULT NULL,
  `expiration_date` date DEFAULT NULL,
  `influence_source` varchar(255) DEFAULT NULL,
  `status` varchar(50) DEFAULT 'Pending',
  `student_type` varchar(50) DEFAULT NULL,
  `admission_number` varchar(100) DEFAULT NULL,
  `accepted_at` datetime DEFAULT NULL,
  `custom_message` text DEFAULT NULL,
  `denial_reason` text DEFAULT NULL,
  `uploaded_files` text DEFAULT NULL,
  `admission_tracking_number` varchar(100) DEFAULT NULL,
  `student_email` varchar(100) DEFAULT NULL,
  `is_programhead` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `name`, `last_name`, `first_name`, `middle_name`, `email`, `password`, `role`, `program`, `student_id`, `employee_id`, `is_archived`, `created_at`, `gender`, `phone`, `dob`, `pob`, `address`, `school_last_attended`, `school_year_attended`, `father_name`, `father_occupation`, `father_contact`, `mother_name`, `mother_occupation`, `mother_contact`, `emergency_contact_name`, `emergency_contact_number`, `learning_mode`, `year_level`, `admission_type`, `source_of_info`, `expiration_date`, `influence_source`, `status`, `student_type`, `admission_number`, `accepted_at`, `custom_message`, `denial_reason`, `uploaded_files`, `admission_tracking_number`, `student_email`, `is_programhead`) VALUES
(75, NULL, 'EVIZA', 'JAMES WARREN', 'J', 'James@mstip.edu.ph', '$2y$10$FgmNCsBi4vchO5yUE4H9oe7f/hJTieCEv0b06z3YzMcx7UfYdbw9.', 'admin', NULL, NULL, '1233456789', 0, '2026-06-03 15:26:15', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'Freshman', NULL, NULL, NULL, 'Pending', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0),
(81, NULL, 'Gray', 'Skylar', 'quagmire', 'GraySky89@gmail.com', '$2y$10$3hTGw1mkkmVcRKU7nPkFJuSAzW5d3Tjjif7Ck4zBev6wGmy4L6Niu', 'student', 'Bachelor of Early Childhood Education', '2026-0004', NULL, 0, '2026-08-15 13:59:18', 'Male', '09683007000', '2004-01-02', 'Lying ing Center', 'Cagsiay II, Mauban, Quezon, CALABARZON', 'Manuel S. Enverga Memorial School of Arts and Trades', '2025-2026', 'Nolan', 'NA', '09683007037', 'Maria', 'NA', '09683007022', 'Nolan', '09683007037', NULL, '1st Year', 'Freshman (New)', 'Relative Studying Here', NULL, NULL, 'Pending', NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'graysky89@gmail.com', 0),
(82, NULL, 'Silva, Gray quagmire', NULL, NULL, 'SilvaGrayQuag89@gmail.com', '$2y$10$S1yjMBBMGNfleDW1k45Yvu3X3O5QdVGokjzeGLs1WuHij9FZIWllu', 'student', NULL, '2026-0005', NULL, 0, '2026-08-16 07:28:38', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'Freshman', NULL, NULL, NULL, 'Pending', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0),
(83, NULL, 'Vance, Nova Sterling', '', '', 'Vance89@gmail.com', '$2y$10$45IGqbf1r7/p3i8V29ziR.TznXmD7PRFsQjAfnv6JOyBwGnAAYFaC', 'student', 'Bachelor of Early Childhood Education', '2026-0006', NULL, 0, '2026-08-16 07:45:45', '', '', '0000-00-00', '', '', '', '', '', '', '', '', '', '', '', '', NULL, NULL, 'Freshman (New)', '', NULL, NULL, 'Pending', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0),
(84, NULL, 'Dela Cruz', 'Juan', '', 'Juan@gmail.com', '$2y$10$CeuNQiAyAK/ax0FNuvwrGe7StLwOEuXaFXQ2AgOERrdEPEziMHfaG', 'teacher', 'Bachelor of Early Childhood Education', NULL, 'EMP-2026-20001', 0, '2026-08-17 09:48:41', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'Freshman', NULL, NULL, NULL, 'Pending', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 1),
(85, NULL, 'Cristobal', 'Cristina', 'B', 'Cristobal@gmail.com', '$2y$10$SJjGgB2L/jshXVbAPyk1FeQrKQQilIX8xg.YEWh8Gc2ZmdqZmEUNS', 'teacher', 'Bachelor of Early Childhood Education', NULL, 'EMP-2026-20002', 0, '2026-08-19 08:05:24', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'Freshman', NULL, NULL, NULL, 'Pending', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 1),
(89, NULL, 'Fay', 'Trisha K', 'Villamarzo', 'FayTrishaK@gmail.com', '$2y$10$DpBuzGOeNLAgkG9IcuhUteNNmA68GFws0R6jo27ih8lAnmwA0TN5a', 'student', 'Bachelor of Science in Information Systems', '23-00001', NULL, 0, '2026-09-02 04:34:45', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '1st Year', 'Freshman (New)', NULL, NULL, NULL, 'Pending', NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'FayTrishaK@gmail.com', 0),
(90, NULL, 'Collera', 'Alyza', 'De Ocampo', 'tashiyadeocampo@gmail.com', '$2y$10$HmdAZ0GDjwVcxXki7WFDXOuIyT7FLBjg/HxkPpVxvgjibR2TlJXTK', 'student', 'Bachelor of Science in Information Systems', '23-00002', NULL, 0, '2026-09-02 05:00:14', 'Female', '09752143606', '2004-09-10', 'Baguio City', 'Quirino Hill, West, City of Baguio, Benguet, CAR', 'Saint Louis School Center', '2022-2023', 'Alberto Collera', 'Business Owner ', '09752143606', 'Liza Collera', 'Business Owner', '09752143606', 'Liza Collera', '09752143606', NULL, '4th Year', 'Freshman (New)', 'Social Media (Facebook / Google)', NULL, NULL, 'Pending', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '123@gmail.com', 0);

--
-- Indexes for dumped tables
--

--
-- Indexes for table `accounting_announcements`
--
ALTER TABLE `accounting_announcements`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `accounting_fees`
--
ALTER TABLE `accounting_fees`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `active_curriculums`
--
ALTER TABLE `active_curriculums`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `admissions`
--
ALTER TABLE `admissions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `admission_number` (`admission_number`);

--
-- Indexes for table `bank_accounts`
--
ALTER TABLE `bank_accounts`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `college_fees`
--
ALTER TABLE `college_fees`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `enrollments`
--
ALTER TABLE `enrollments`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_enrollment` (`student_id`,`subject_id`);

--
-- Indexes for table `enrollment_requests`
--
ALTER TABLE `enrollment_requests`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `entrance_exams`
--
ALTER TABLE `entrance_exams`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `exam_questions`
--
ALTER TABLE `exam_questions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `exam_id` (`exam_id`);

--
-- Indexes for table `exam_results`
--
ALTER TABLE `exam_results`
  ADD PRIMARY KEY (`id`),
  ADD KEY `exam_id` (`exam_id`);

--
-- Indexes for table `portal_settings`
--
ALTER TABLE `portal_settings`
  ADD PRIMARY KEY (`setting_key`);

--
-- Indexes for table `programs`
--
ALTER TABLE `programs`
  ADD PRIMARY KEY (`program_id`),
  ADD UNIQUE KEY `program_name` (`program_name`);

--
-- Indexes for table `program_defaults`
--
ALTER TABLE `program_defaults`
  ADD PRIMARY KEY (`program`);

--
-- Indexes for table `program_heads`
--
ALTER TABLE `program_heads`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `program_name` (`program_name`);

--
-- Indexes for table `prospectus`
--
ALTER TABLE `prospectus`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `prospectus_update_requests`
--
ALTER TABLE `prospectus_update_requests`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `registrar_announcements`
--
ALTER TABLE `registrar_announcements`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `registrar_fees`
--
ALTER TABLE `registrar_fees`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `registrar_memos`
--
ALTER TABLE `registrar_memos`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `section_settings`
--
ALTER TABLE `section_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_section` (`semester`,`program`,`year_level`,`section_name`);

--
-- Indexes for table `source_of_info_list`
--
ALTER TABLE `source_of_info_list`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `source_name` (`source_name`);

--
-- Indexes for table `student_grades`
--
ALTER TABLE `student_grades`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `system_settings`
--
ALTER TABLE `system_settings`
  ADD PRIMARY KEY (`setting_key`);

--
-- Indexes for table `tbl_fees`
--
ALTER TABLE `tbl_fees`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `teaching_applications`
--
ALTER TABLE `teaching_applications`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `transaction_history`
--
ALTER TABLE `transaction_history`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `idx_unique_or` (`or_number`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD UNIQUE KEY `student_id` (`student_id`),
  ADD UNIQUE KEY `employee_id` (`employee_id`),
  ADD UNIQUE KEY `student_id_2` (`student_id`),
  ADD UNIQUE KEY `employee_id_2` (`employee_id`),
  ADD UNIQUE KEY `student_id_3` (`student_id`),
  ADD UNIQUE KEY `employee_id_3` (`employee_id`),
  ADD UNIQUE KEY `student_id_4` (`student_id`),
  ADD UNIQUE KEY `employee_id_4` (`employee_id`),
  ADD UNIQUE KEY `student_id_5` (`student_id`),
  ADD UNIQUE KEY `employee_id_5` (`employee_id`),
  ADD UNIQUE KEY `student_id_6` (`student_id`),
  ADD UNIQUE KEY `employee_id_6` (`employee_id`),
  ADD UNIQUE KEY `student_id_7` (`student_id`),
  ADD UNIQUE KEY `employee_id_7` (`employee_id`),
  ADD UNIQUE KEY `student_id_8` (`student_id`),
  ADD UNIQUE KEY `employee_id_8` (`employee_id`),
  ADD UNIQUE KEY `student_id_9` (`student_id`),
  ADD UNIQUE KEY `employee_id_9` (`employee_id`),
  ADD UNIQUE KEY `student_id_10` (`student_id`),
  ADD UNIQUE KEY `employee_id_10` (`employee_id`),
  ADD UNIQUE KEY `student_id_11` (`student_id`),
  ADD UNIQUE KEY `employee_id_11` (`employee_id`),
  ADD UNIQUE KEY `student_id_12` (`student_id`),
  ADD UNIQUE KEY `employee_id_12` (`employee_id`),
  ADD UNIQUE KEY `student_id_13` (`student_id`),
  ADD UNIQUE KEY `employee_id_13` (`employee_id`),
  ADD UNIQUE KEY `student_id_14` (`student_id`),
  ADD UNIQUE KEY `employee_id_14` (`employee_id`),
  ADD UNIQUE KEY `student_id_15` (`student_id`),
  ADD UNIQUE KEY `employee_id_15` (`employee_id`),
  ADD UNIQUE KEY `student_id_16` (`student_id`),
  ADD UNIQUE KEY `employee_id_16` (`employee_id`),
  ADD UNIQUE KEY `student_id_17` (`student_id`),
  ADD UNIQUE KEY `employee_id_17` (`employee_id`),
  ADD UNIQUE KEY `student_id_18` (`student_id`),
  ADD UNIQUE KEY `employee_id_18` (`employee_id`),
  ADD UNIQUE KEY `student_id_19` (`student_id`),
  ADD UNIQUE KEY `employee_id_19` (`employee_id`),
  ADD UNIQUE KEY `student_id_20` (`student_id`),
  ADD UNIQUE KEY `employee_id_20` (`employee_id`),
  ADD UNIQUE KEY `student_id_21` (`student_id`),
  ADD UNIQUE KEY `employee_id_21` (`employee_id`),
  ADD UNIQUE KEY `student_id_22` (`student_id`),
  ADD UNIQUE KEY `employee_id_22` (`employee_id`),
  ADD UNIQUE KEY `student_id_23` (`student_id`),
  ADD UNIQUE KEY `employee_id_23` (`employee_id`),
  ADD UNIQUE KEY `student_id_24` (`student_id`),
  ADD UNIQUE KEY `employee_id_24` (`employee_id`),
  ADD UNIQUE KEY `student_id_25` (`student_id`),
  ADD UNIQUE KEY `employee_id_25` (`employee_id`),
  ADD UNIQUE KEY `student_id_26` (`student_id`),
  ADD UNIQUE KEY `employee_id_26` (`employee_id`),
  ADD UNIQUE KEY `student_id_27` (`student_id`),
  ADD UNIQUE KEY `employee_id_27` (`employee_id`),
  ADD UNIQUE KEY `student_id_28` (`student_id`),
  ADD UNIQUE KEY `employee_id_28` (`employee_id`),
  ADD UNIQUE KEY `student_id_29` (`student_id`),
  ADD UNIQUE KEY `employee_id_29` (`employee_id`),
  ADD UNIQUE KEY `student_id_30` (`student_id`),
  ADD UNIQUE KEY `employee_id_30` (`employee_id`),
  ADD UNIQUE KEY `student_id_31` (`student_id`),
  ADD UNIQUE KEY `employee_id_31` (`employee_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `accounting_announcements`
--
ALTER TABLE `accounting_announcements`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `accounting_fees`
--
ALTER TABLE `accounting_fees`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `active_curriculums`
--
ALTER TABLE `active_curriculums`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `admissions`
--
ALTER TABLE `admissions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=26;

--
-- AUTO_INCREMENT for table `bank_accounts`
--
ALTER TABLE `bank_accounts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `college_fees`
--
ALTER TABLE `college_fees`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `enrollments`
--
ALTER TABLE `enrollments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=23;

--
-- AUTO_INCREMENT for table `enrollment_requests`
--
ALTER TABLE `enrollment_requests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=31;

--
-- AUTO_INCREMENT for table `entrance_exams`
--
ALTER TABLE `entrance_exams`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `exam_questions`
--
ALTER TABLE `exam_questions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `exam_results`
--
ALTER TABLE `exam_results`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `programs`
--
ALTER TABLE `programs`
  MODIFY `program_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- AUTO_INCREMENT for table `program_heads`
--
ALTER TABLE `program_heads`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `prospectus`
--
ALTER TABLE `prospectus`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=918;

--
-- AUTO_INCREMENT for table `prospectus_update_requests`
--
ALTER TABLE `prospectus_update_requests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `registrar_announcements`
--
ALTER TABLE `registrar_announcements`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `registrar_fees`
--
ALTER TABLE `registrar_fees`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- AUTO_INCREMENT for table `registrar_memos`
--
ALTER TABLE `registrar_memos`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `section_settings`
--
ALTER TABLE `section_settings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `source_of_info_list`
--
ALTER TABLE `source_of_info_list`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `student_grades`
--
ALTER TABLE `student_grades`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tbl_fees`
--
ALTER TABLE `tbl_fees`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `teaching_applications`
--
ALTER TABLE `teaching_applications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16;

--
-- AUTO_INCREMENT for table `transaction_history`
--
ALTER TABLE `transaction_history`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=91;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `exam_questions`
--
ALTER TABLE `exam_questions`
  ADD CONSTRAINT `exam_questions_ibfk_1` FOREIGN KEY (`exam_id`) REFERENCES `entrance_exams` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `exam_results`
--
ALTER TABLE `exam_results`
  ADD CONSTRAINT `exam_results_ibfk_1` FOREIGN KEY (`exam_id`) REFERENCES `entrance_exams` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
