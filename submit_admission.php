<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

include "dbconn.php";
require "env.php"; // Required for APP_URL and SMTP constants
require 'PHPMailer/Exception.php';
require 'PHPMailer/PHPMailer.php';
require 'PHPMailer/SMTP.php';

date_default_timezone_set('Asia/Manila');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // 1. Check for Re-application (if coming from a denial link)
    $is_reapply = isset($_POST['reapply_adm_no']) && !empty($_POST['reapply_adm_no']);
    
    if ($is_reapply) {
        $admission_number = mysqli_real_escape_string($conn, $_POST['reapply_adm_no']);
    } else {
        // --- SECURE RANDOMIZED ID GENERATOR ---
        // Generates YYYY-[12 Random Digits] (e.g., 2026-847291048271)
        $year = date('Y');
        $is_unique = false;
        $admission_number = '';

        while (!$is_unique) {
            $random_digits = '';
            for ($i = 0; $i < 12; $i++) {
                $random_digits .= mt_rand(0, 9);
            }
            $temp_adm = $year . "-" . $random_digits;
            
            // Absolute Failsafe: Check if this 12-digit string already exists in the DB
            $chk = $conn->query("SELECT id FROM admissions WHERE admission_number = '$temp_adm'");
            if ($chk && $chk->num_rows === 0) {
                $admission_number = $temp_adm;
                $is_unique = true;
            }
        }
    }

    // 2. Collect & Sanitize ALL Form Data
    $student_type  = mysqli_real_escape_string($conn, $_POST['student_type'] ?? 'Freshman');
    $program       = mysqli_real_escape_string($conn, $_POST['program'] ?? '');
    $year_level    = mysqli_real_escape_string($conn, $_POST['year_level'] ?? '');
    
    // Separate variables for names
    $last_name     = mysqli_real_escape_string($conn, $_POST['last_name'] ?? '');
    $first_name    = mysqli_real_escape_string($conn, $_POST['first_name'] ?? '');
    $middle_name   = mysqli_real_escape_string($conn, $_POST['middle_name'] ?? '');

    $email         = mysqli_real_escape_string($conn, $_POST['email'] ?? '');
    $gender        = mysqli_real_escape_string($conn, $_POST['gender'] ?? '');
    $phone         = mysqli_real_escape_string($conn, $_POST['phone'] ?? '');
    $dob           = mysqli_real_escape_string($conn, $_POST['dob'] ?? '');
    $pob           = mysqli_real_escape_string($conn, $_POST['pob'] ?? '');
    $address       = mysqli_real_escape_string($conn, $_POST['address'] ?? '');
    
    $school_last   = mysqli_real_escape_string($conn, $_POST['school_last_attended'] ?? '');
    $school_year   = mysqli_real_escape_string($conn, $_POST['school_year_attended'] ?? '');
    
    $f_name        = mysqli_real_escape_string($conn, $_POST['father_name'] ?? '');
    $f_occ         = mysqli_real_escape_string($conn, $_POST['father_occupation'] ?? '');
    $f_con         = mysqli_real_escape_string($conn, $_POST['father_contact'] ?? '');
    
    $m_name        = mysqli_real_escape_string($conn, $_POST['mother_name'] ?? '');
    $m_occ         = mysqli_real_escape_string($conn, $_POST['mother_occupation'] ?? '');
    $m_con         = mysqli_real_escape_string($conn, $_POST['mother_contact'] ?? '');
    
    $em_name       = mysqli_real_escape_string($conn, $_POST['emergency_contact_name'] ?? '');
    $em_con        = mysqli_real_escape_string($conn, $_POST['emergency_contact_number'] ?? '');
    
    $influence     = mysqli_real_escape_string($conn, $_POST['influence_source'] ?? '');

    // Construct Display Name for Email
    $display_fullname = trim(ucwords(strtolower("$first_name $last_name")));

    // 3. Handle Specific File Uploads
    $uploaded_paths = [];
    $target_dir = "uploads/admissions/";
    if (!is_dir($target_dir)) mkdir($target_dir, 0777, true);

    $files_to_process = ['file_2x2', 'file_psa'];

    foreach ($files_to_process as $file_input_name) {
        if (isset($_FILES[$file_input_name]) && $_FILES[$file_input_name]['error'] == 0) {
            $ext = pathinfo($_FILES[$file_input_name]['name'], PATHINFO_EXTENSION);
            // Rename file securely: ADM-NUMBER_INPUT-NAME_TIMESTAMP.ext
            $filename = $admission_number . "_" . $file_input_name . "_" . time() . "." . $ext;
            $target_file = $target_dir . $filename;
            
            if (move_uploaded_file($_FILES[$file_input_name]['tmp_name'], $target_file)) {
                $uploaded_paths[] = $target_file;
            }
        }
    }
    $uploaded_files_str = implode(',', $uploaded_paths);

    // 4. Update or Insert into Database
    if ($is_reapply) {
        $sql = "UPDATE admissions SET 
                student_type = ?, program = ?, year_level = ?, 
                last_name = ?, first_name = ?, middle_name = ?, 
                email = ?, gender = ?, phone = ?, dob = ?, pob = ?, address = ?, 
                school_last_attended = ?, school_year_attended = ?, father_name = ?, 
                father_occupation = ?, father_contact = ?, mother_name = ?, 
                mother_occupation = ?, mother_contact = ?, emergency_contact_name = ?, 
                emergency_contact_number = ?, influence_source = ?, status = 'Pending', 
                denial_reason = NULL";
        
        if (!empty($uploaded_files_str)) {
            $sql .= ", uploaded_files = '$uploaded_files_str'";
        }
        
        $sql .= " WHERE admission_number = ?";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ssssssssssssssssssssssss", 
            $student_type, $program, $year_level, 
            $last_name, $first_name, $middle_name, 
            $email, $gender, $phone, $dob, $pob, $address, 
            $school_last, $school_year, $f_name, 
            $f_occ, $f_con, $m_name, 
            $m_occ, $m_con, $em_name, 
            $em_con, $influence, $admission_number
        );
    } else {
        $sql = "INSERT INTO admissions (
                    admission_number, student_type, program, year_level, 
                    last_name, first_name, middle_name, 
                    email, gender, phone, dob, pob, address, 
                    school_last_attended, school_year_attended, father_name, 
                    father_occupation, father_contact, mother_name, 
                    mother_occupation, mother_contact, emergency_contact_name, 
                    emergency_contact_number, influence_source, uploaded_files, 
                    status, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending', NOW())";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("sssssssssssssssssssssssss", 
            $admission_number, $student_type, $program, $year_level, 
            $last_name, $first_name, $middle_name, 
            $email, $gender, $phone, $dob, $pob, $address, 
            $school_last, $school_year, $f_name, 
            $f_occ, $f_con, $m_name, 
            $m_occ, $m_con, $em_name, 
            $em_con, $influence, $uploaded_files_str
        );
    }

    if ($stmt->execute()) {
        
        // 5. SEND AUTOMATED CONFIRMATION EMAIL VIA PHPMAILER
        
        // --- MAGIC URL TRICK ---
        // By appending ?adm_no= it auto-fills and searches the tracking portal automatically
        $track_link = rtrim(APP_URL, '/') . "/admission_form_registration/track_status.php?adm_no=" . urlencode($admission_number);

        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host       = SMTP_HOST;
            $mail->SMTPAuth   = true;
            $mail->Username   = SMTP_USER;
            $mail->Password   = SMTP_PASS;
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = SMTP_PORT;
            // Bypass SSL checks for local XAMPP testing
            $mail->SMTPOptions = ['ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true]];

            $mail->setFrom(SMTP_USER, 'LDSP Admissions Office');
            $mail->addAddress($email, $display_fullname); 

            $mail->isHTML(true);
            $mail->Subject = 'LDSP Admissions - Application Received';
            
            // Cleaned up the "Click to copy" hints since email clients block JS. Auto-fill button takes over.
            $mail->Body = "
                <div style='font-family: Helvetica, Arial, sans-serif; color: #1f2937; max-width: 600px; margin: 0 auto; border: 1px solid #e5e7eb; border-radius: 12px; overflow: hidden; background-color: #ffffff; width: 100%; box-sizing: border-box;'>
                    <div style='padding: 20px; border-bottom: 1px solid #e5e7eb; background-color: #f8fafc;'>
                        <span style='color: #00205b; font-weight: bold; font-size: 16px; letter-spacing: 1px;'>✓ APPLICATION RECEIVED</span>
                    </div>
                    <div style='padding: 24px; background-color: #ffffff; border-radius: 8px; margin: 16px; border: 1px solid #e2e8f0; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05); box-sizing: border-box;'>
                        <p style='font-size: 15px; margin-top: 0;'>Dear <strong>$display_fullname</strong>,</p>
                        <p style='line-height: 1.6; font-size: 14px;'>Thank you for choosing Lyceum de San Pablo. Your application has been successfully submitted and is currently <strong>Under Review</strong>.</p>
                        
                        <div style='background-color: #f1f5f9; padding: 16px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #c5a02c; box-sizing: border-box;'>
                            <p style='margin: 0 0 5px 0; font-size: 10px; color: #64748b; font-weight: bold; text-transform: uppercase; letter-spacing: 1px;'>Your Official Tracking Number</p>
                            
                            <p style='margin: 0 0 15px 0; font-size: 16px; font-family: monospace; font-weight: 900; color: #00205b; letter-spacing: 1px; word-break: break-all; -webkit-user-select: all; user-select: all; padding: 8px; background-color: #e2e8f0; border-radius: 4px; display: inline-block; width: 100%; box-sizing: border-box; text-align: center;'>$admission_number</p>
                            
                            <p style='margin: 0 0 5px 0; font-size: 10px; color: #64748b; font-weight: bold; text-transform: uppercase; letter-spacing: 1px;'>Program Applied For</p>
                            <p style='margin: 0 0 15px 0; font-size: 13px; font-weight: bold; color: #1f2937;'>$program</p>

                            <p style='margin: 0 0 5px 0; font-size: 10px; color: #64748b; font-weight: bold; text-transform: uppercase; letter-spacing: 1px;'>Applicant Type</p>
                            <p style='margin: 0; font-size: 13px; font-weight: bold; color: #1f2937;'>$student_type - $year_level</p>
                        </div>

                        <p style='line-height: 1.6; color: #475569; font-size: 14px;'>Please keep this tracking number safe. You can tap the button below to instantly view your application progress, check evaluation results, and take your Entrance Examination.</p>
                        
                        <p style='margin: 0; color: #64748b; margin-top: 24px; font-size: 13px;'>Sincerely,</p>
                        <p style='margin: 0; color: #64748b; font-size: 13px;'><strong>LDSP Admissions Office</strong></p>
                    </div>
                    <div style='padding: 0 16px 24px 16px; text-align: center;'>
                        <!-- Modified text to indicate auto-tracking -->
                        <a href='{$track_link}' style='background-color: #00205b; color: #ffffff; padding: 14px 24px; border-radius: 8px; text-decoration: none; font-weight: bold; font-size: 13px; display: inline-block; text-transform: uppercase; letter-spacing: 1px; width: auto; max-width: 100%; box-sizing: border-box;'>Auto-Track Status Here &rarr;</a>
                    </div>
                </div>
            ";
            
            $mail->AltBody = "Dear $display_fullname,\n\nApplication successful! Here is your admission number and registered information:\n\nAdmission Number: $admission_number\nProgram: $program\nApplicant Type: $student_type - $year_level\n\nPlease wait till the documents are verified. You may check your status directly by visiting this link: $track_link\n\nSincerely,\nLDSP Admissions Office";

            $mail->send();
        } catch (Exception $e) {
            // Silently catch errors so the user is still redirected successfully to the success screen
        }

        // Redirect to success page
        header("Location: admission_success.php?adm_no=" . $admission_number);
        exit();
    } else {
        die("Mainframe Database Sync Error: " . $conn->error);
    }
}
?>