<?php
session_start();
include 'config.php';

// Verify user is logged in and has the correct role
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

if ($_SESSION['role'] !== 'dental') {
    header("Location: dental.php");
    exit();
}

// Handle API requests
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    
    try {
        switch ($_GET['action']) {
            case 'get_appointments':
                $date = $_GET['date'] ?? date('Y-m-d');
                $formattedDate = date('m/d/Y', strtotime($date));
                
                $stmt = $conn->prepare("SELECT * FROM appointments WHERE appointment_date = ? AND healthcare_provider = 'Dental'");
                $stmt->execute([$formattedDate]);
                $appointments = $stmt->fetchAll(PDO::FETCH_ASSOC);
                echo json_encode($appointments);
                break;
                
            case 'get_dashboard_data':
                $date = $_GET['date'] ?? date('Y-m-d');
                $formattedDate = date('m/d/Y', strtotime($date));
                
                $stmt = $conn->prepare("SELECT 
                    COUNT(*) as total,
                    SUM(status = 'PENDING') as pending,
                    SUM(status = 'COMPLETED') as completed,
                    SUM(status = 'CANCELED') as canceled
                    FROM appointments 
                    WHERE appointment_date = ? AND healthcare_provider = 'Dental'");
                $stmt->execute([$formattedDate]);
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                echo json_encode($result ?: ['total' => 0, 'pending' => 0, 'completed' => 0, 'canceled' => 0]);
                break;
                
            case 'update_appointment':
                $id = $_POST['id'];
                $status = $_POST['status'];
                
                $stmt = $conn->prepare("UPDATE appointments SET status = ? WHERE id = ?");
                $stmt->execute([$status, $id]);
                echo json_encode(['success' => true]);
                break;
                
            case 'get_patient':
                $patientId = $_GET['patientId'];
                $stmt = $conn->prepare("SELECT * FROM residents WHERE id = ?");
                $stmt->execute([$patientId]);
                $patient = $stmt->fetch(PDO::FETCH_ASSOC);
                echo json_encode($patient ?: ['error' => 'Patient not found']);
                break;
                
            case 'submit_dental_history':
                $data = json_decode(file_get_contents('php://input'), true);
                
                $stmt = $conn->prepare("INSERT INTO dental_records (
                    patient_id, patient_name, reason_for_visit, treatment_details, 
                    preventive_care, gums_condition, oral_tissues, medical_conditions, 
                    medications, medication_details, allergies, allergy_details, 
                    previous_treatment, previous_treatment_details, symptoms, 
                    followup_plan, brushing_frequency, flossing, mouthwash, form_id
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                
                $stmt->execute([
                    $data['patientId'],
                    $data['patientName'],
                    $data['reasonForVisit'],
                    $data['treatmentDetails'],
                    $data['preventiveCare'],
                    $data['gumsCondition'],
                    $data['oralTissues'],
                    json_encode($data['medicalConditions']),
                    $data['medications'],
                    $data['medicationDetails'],
                    $data['allergies'],
                    $data['allergyDetails'],
                    $data['previousTreatment'],
                    $data['previousTreatmentDetails'],
                    json_encode($data['symptoms']),
                    $data['followupPlan'],
                    $data['brushingFrequency'],
                    $data['flossing'],
                    $data['mouthwash'],
                    $data['formId']
                ]);
                
                echo json_encode(['success' => true, 'formId' => $data['formId']]);
                break;
                
            case 'get_dental_records':
                $page = $_GET['page'] ?? 1;
                $search = $_GET['search'] ?? '';
                $limit = 10;
                $offset = ($page - 1) * $limit;
                
                if ($search) {
                    $stmt = $conn->prepare("SELECT * FROM dental_records 
                                          WHERE patient_name LIKE ? OR form_id LIKE ?
                                          ORDER BY id DESC LIMIT ? OFFSET ?");
                    $searchTerm = "%$search%";
                    $stmt->execute([$searchTerm, $searchTerm, $limit, $offset]);
                    $countStmt = $conn->prepare("SELECT COUNT(*) FROM dental_records 
                                               WHERE patient_name LIKE ? OR form_id LIKE ?");
                    $countStmt->execute([$searchTerm, $searchTerm]);
                } else {
                    $stmt = $conn->prepare("SELECT * FROM dental_records 
                                          ORDER BY id DESC LIMIT ? OFFSET ?");
                    $stmt->execute([$limit, $offset]);
                    $countStmt = $conn->prepare("SELECT COUNT(*) FROM dental_records");
                    $countStmt->execute();
                }
                
                $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $total = $countStmt->fetchColumn();
                
                echo json_encode([
                    'records' => $records,
                    'total' => $total,
                    'pages' => ceil($total / $limit)
                ]);
                break;
                
            case 'get_dental_record':
                $formId = $_GET['formId'];
                $stmt = $conn->prepare("SELECT * FROM dental_records WHERE form_id = ?");
                $stmt->execute([$formId]);
                $record = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($record) {
                    $record['medicalConditions'] = json_decode($record['medicalConditions'], true);
                    $record['symptoms'] = json_decode($record['symptoms'], true);
                }
                
                echo json_encode($record ?: ['error' => 'Record not found']);
                break;
                
            case 'time_in_out':
                $userId = $_SESSION['user_id'];
                $todayDate = date('Y-m-d');
                $currentTime = date('H:i:s');
                
                $stmt = $conn->prepare("SELECT * FROM time_records WHERE user_id = ? AND date = ?");
                $stmt->execute([$userId, $todayDate]);
                $record = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($_GET['type'] === 'in') {
                    if ($record) {
                        echo json_encode(['success' => false, 'message' => 'Already timed in today']);
                    } else {
                        $stmt = $conn->prepare("INSERT INTO time_records (user_id, date, time_in) VALUES (?, ?, ?)");
                        $stmt->execute([$userId, $todayDate, $currentTime]);
                        echo json_encode(['success' => true]);
                    }
                } else {
                    if (!$record || $record['time_out']) {
                        echo json_encode(['success' => false, 'message' => 'You must time in first']);
                    } else {
                        $stmt = $conn->prepare("UPDATE time_records SET time_out = ? WHERE id = ?");
                        $stmt->execute([$currentTime, $record['id']]);
                        echo json_encode(['success' => true]);
                    }
                }
                break;
                
            case 'get_time_records':
                $userId = $_SESSION['user_id'];
                $stmt = $conn->prepare("SELECT * FROM time_records WHERE user_id = ? ORDER BY date DESC");
                $stmt->execute([$userId]);
                $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
                echo json_encode($records);
                break;
                
            default:
                echo json_encode(['error' => 'Invalid action']);
        }
    } catch(PDOException $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit();
}

// Get user data for display
$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Barangay Old Capitol Healthcenter</title>
    <link rel="stylesheet" href="styles.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/limonte-sweetalert2/7.2.0/sweetalert2.min.css">
    <style>
        /* All your existing CSS styles from dental.html */
        .navbar {
            background-color: #007bff;
            color: white;
            padding: 10px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .sidebar {
            width: 250px;
            background-color: #f8f9fa;
            height: 100vh;
            position: fixed;
            padding-top: 20px;
        }
        .content {
            margin-left: 250px;
            padding: 20px;
        }
        .statistic-card {
            margin-bottom: 20px;
            border-radius: 10px;
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }
        .icon-container {
            font-size: 2rem;
            margin-bottom: 10px;
        }
        .calendar-day {
            cursor: pointer;
            padding: 5px;
            text-align: center;
        }
        .calendar-day:hover {
            background-color: #f0f0f0;
        }
        .calendar-day.selected {
            background-color: #007bff;
            color: white;
        }
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
            justify-content: center;
            align-items: center;
        }
        .modal-content {
            background-color: white;
            padding: 20px;
            border-radius: 5px;
            width: 80%;
            max-width: 800px;
            max-height: 80vh;
            overflow-y: auto;
        }
        .table-container {
            overflow-x: auto;
        }
        .resident-details {
            padding: 15px;
            background: #f9f9f9;
            border-radius: 8px;
        }
        .detail-row {
            display: flex;
            margin-bottom: 10px;
            padding-bottom: 10px;
            border-bottom: 1px solid #eee;
        }
        .detail-label {
            font-weight: bold;
            width: 150px;
            color: #555;
        }
        .detail-value {
            flex: 1;
        }
        .resident-details h3 {
            color: #2c3e50;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .close {
            color: #aaa;
            float: right;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
        }
        .close:hover {
            color: black;
        }
        .calendar-container {
            display: flex;
            gap: 20px;
            margin-top: 20px;
        }
        .calendar {
            flex: 1;
            max-width: 400px;
        }
        .schedule {
            flex: 2;
        }
        .appointment-list {
            list-style: none;
            padding: 0;
        }
        .appointment-item {
            padding: 10px;
            border-bottom: 1px solid #eee;
        }
        .pagination {
            margin-top: 20px;
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 10px;
        }
    </style>
</head>
<body>

<!-- Navbar -->
<div class="navbar">
    <label>Barangay Old Capitol Healthcenter</label>
    <div class="user-icon">
        <div class="text-center mb-3" id="profileImageContainer" onclick="toggleUserMenu()">
            <i class="fa-solid fa-user-circle"></i>
        </div>
        <div id="userMenu" class="dropdown-menu" style="display: none;">
            <a href="#" onclick="showLogoutModal()" class="dropdown-item">
                <i class="fa-solid fa-sign-out-alt"></i> Logout
            </a>
        </div>
    </div>
</div>

<!-- Sidebar -->
<div class="sidebar" id="sidebar">
    <ul class="navbar-nav flex-column">
        <li class="nav-item">
            <a class="nav-link d-flex align-items-center" href="#" onclick="showSection('dashboardSection')">
                <i class="fa-solid fa-house mr-2"></i> 
                Dashboard
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link d-flex align-items-center" href="#" onclick="showSection('appointmentSection')">
                <i class="fa-solid fa-phone mr-2"></i> 
                Appointment
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link d-flex align-items-center" href="#" onclick="showSection('consultationSection')">
                <i class="fa-solid fa-user-md mr-2"></i>
                Consultation
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link d-flex align-items-center" href="#" onclick="showSection('dentalRecordsSection')">
                <i class="fa-solid fa-file-medical mr-2"></i>
                Patient Records
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link d-flex align-items-center" href="#" onclick="showSection('timeInOutSection')">
                <i class="fa-solid fa-clock mr-2"></i> 
                Time In/Time Out
            </a>
        </li>
    </ul>
</div>

<!-- Main Content -->
<div class="content">
    <!-- Dashboard Section -->
    <div id="dashboardSection" class="content-section">
        <h1 class="mt-5">Dental Appointment Dashboard</h1>
        
        <div class="row mb-4 right flexend">
            <div class="col-md-3 offset-md-4">
                <label>Select Date: </label>
                <input type="date" id="appointmentDatePicker" class="form-control">
            </div>
        </div>

        <div class="row">
            <div class="col-md-3">
                <div class="card statistic-card text-center">
                    <div class="card-body">
                        <div class="icon-container">
                            <i class="fas fa-calendar-check icon-style"></i>
                        </div>
                        <h5 class="card-title">Total Appointments</h5>
                        <h2 id="totalAppointments">0</h2>
                    </div>
                </div>
            </div>
    
            <div class="col-md-3">
                <div class="card statistic-card text-center">
                    <div class="card-body">
                        <div class="icon-container">
                            <i class="fas fa-clock icon-style"></i>
                        </div>
                        <h5 class="card-title">Pending Appointments</h5>
                        <h2 id="pendingAppointments">0</h2>
                    </div>
                </div>
            </div>
    
            <div class="col-md-3">
                <div class="card statistic-card text-center">
                    <div class="card-body">
                        <div class="icon-container">
                            <i class="fas fa-check-circle icon-style"></i>
                        </div>
                        <h5 class="card-title">Completed Appointments</h5>
                        <h2 id="completedAppointments">0</h2>
                    </div>
                </div>
            </div>
    
            <div class="col-md-3">
                <div class="card statistic-card text-center">
                    <div class="card-body">
                        <div class="icon-container">
                            <i class="fas fa-times-circle icon-style"></i>
                        </div>
                        <h5 class="card-title">Canceled Appointments</h5>
                        <h2 id="canceledAppointments">0</h2>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Appointment Section -->
    <div id="appointmentSection" class="content-section" style="display: none;">
        <h1 class="mt-5">Dental Appointment Calendar</h1>
        <div class="calendar-container">
            <div class="calendar">
                <div class="calendar-header">
                    <button id="prevMonth" class="btn btn-secondary">
                        <i class="fas fa-chevron-left"></i>
                    </button>
                    <span id="currentMonth"></span>
                    <button id="nextMonth" class="btn btn-secondary">
                        <i class="fas fa-chevron-right"></i>
                    </button>
                </div>
                <select id="yearSelect" class="form-select" style="width: auto; display: inline-block;"></select>
                <div class="calendar-body" id="calendarDays"></div>
            </div>

            <div class="schedule">
                <div class="schedule-header">Appointments</div>
                <ul class="appointment-list" id="appointmentList">
                    <li>Select a date to view appointments.</li>
                </ul>
            </div>
        </div>
    </div>

    <!-- Consultation Section -->
    <div id="consultationSection" class="content-section" style="display: none;">
        <div>
            <label for="patientIdInput">Enter Resident ID:</label>
            <input type="text" style="width: 20%;" id="patientIdInput" placeholder="Resident ID" />
            <button class="btn btn-primary me-3" onclick="fetchPatientData()">Show Patient Data</button>
        </div>
          
        <div id="patientDataSection" style="display: none;">
            <h1 class="mt-5">Patient Information</h1>
            <p><strong>Resident ID:</strong> <span id="patientId"></span></p>
            <p><strong>Name:</strong> <span id="patientName"></span></p>
            <p><strong>Age:</strong> <span id="patientAge"></span></p>
            <p><strong>Sex:</strong> <span id="patientSex"></span></p>
            <p><strong>Address:</strong> <span id="patientAddress"></span></p>
        </div>         

        <h1 class="mt-5">Dental Record Form</h1>
        <div class="row">
            <div class="col">    
                <label class="mt-2">Do you have any of the following medical conditions? (Check all that apply)</label><br />
                <input type="checkbox" id="diabetes">
                <label for="diabetes" class="mt-2">Diabetes</label><br />
                <input type="checkbox" id="heartDisease">
                <label for="heartDisease">Heart Disease</label><br />
                <input type="checkbox" id="hypertension">
                <label for="hypertension">Hypertension</label><br />
                <input type="checkbox" id="asthma">
                <label for="asthma">Asthma</label><br />
                <input type="checkbox" id="hiv">
                <label for="hiv">HIV/AIDS</label><br />
                <input type="checkbox" id="otherMedical">
                <label for="otherMedical">Other:</label>
                <input type="text" id="otherMedicalDetails" placeholder="Specify other conditions">

                <label class="mt-2">Are you currently taking any medications?</label><br />
                <input type="radio" id="medicationYes" name="medication" value="Yes">
                <label for="medicationYes" class="mt-2 me-3">Yes</label>
                <input type="radio" id="medicationNo" name="medication" value="No">
                <label for="medicationNo">No</label>
                <input type="text" id="medicationDetails" placeholder="If yes, specify medications" disabled>

                <label>Do you have any allergies to medications, food, or dental materials?</label><br />
                <input type="radio" id="allergyYes" name="allergy" value="Yes">
                <label for="allergyYes" class="mt-2 me-3">Yes</label>
                <input type="radio" id="allergyNo" name="allergy" value="No">
                <label for="allergyNo">No</label>
                <input type="text" id="allergyDetails" placeholder="If yes, specify allergies" disabled>

                <label>Have you had any previous dental treatment?</label><br />
                <input type="radio" id="previousTreatmentYes" name="previousTreatment" value="Yes">
                <label for="previousTreatmentYes" class="mt-2 me-3">Yes</label>
                <input type="radio" id="previousTreatmentNo" name="previousTreatment" value="No">
                <label for="previousTreatmentNo">No</label><br />

                <label class="mt-2" for="previousTreatmentDetails">Previous Treatment Details:</label>
                <div class="form-group mt-2">
                    <select id="previousTreatmentDetails" class="form-control">
                        <option value="">-- Select Treatment --</option>
                        <option value="filling">Filling</option>
                        <option value="extraction">Extraction</option>
                        <option value="rootCanal">Root Canal</option>
                        <option value="scaling">Scaling</option>
                        <option value="crowns">Crowns</option>
                        <option value="implants">Implants</option>
                        <option value="other">Other (Specify Below)</option>
                    </select>
                </div>

                <label class="mt-2">Do you experience any of the following? (Check all that apply)</label><br />
                <input type="checkbox" id="toothache">
                <label for="toothache" class="mt-2">Toothache</label><br />
                <input type="checkbox" id="sensitivity">
                <label for="sensitivity">Sensitivity to hot or cold</label><br />
                <input type="checkbox" id="bleedingGums">
                <label for="bleedingGums">Bleeding gums</label><br />
                <input type="checkbox" id="badBreath">
                <label for="badBreath">Bad breath</label><br />
                <input type="checkbox" id="swollenGums">
                <label for="swollenGums">Swollen gums</label><br />
                <input type="checkbox" id="looseTeeth">
                <label for="looseTeeth">Loose teeth</label><br />
                <input type="checkbox" id="otherSymptoms">
                <label for="otherSymptoms">Other:</label>
                <input type="text" id="otherSymptomsDetails" placeholder="Specify other symptoms">

                <label class="mt-2">How often do you brush your teeth?</label><br />
                <input type="radio" id="brushOnce" name="brushFrequency" value="Once a day">
                <label for="brushOnce" class="mt-2 me-3">Once a day</label>
                <input type="radio" id="brushTwice" name="brushFrequency" value="Twice a day">
                <label for="brushTwice" class="mt-2 me-3">Twice a day</label>
                <input type="radio" id="brushMore" name="brushFrequency" value="More than twice a day">
                <label for="brushMore">More than twice a day</label>
            </div>     

            <div class="col"> 
                <label class="mt-2">Do you use dental floss or other cleaning tools?</label><br />
                <input type="radio" id="flossYes" name="floss" value="Yes">
                <label for="flossYes" class="mt-2 me-3">Yes</label>
                <input type="radio" id="flossNo" name="floss" value="No">
                <label for="flossNo">No</label><br />

                <label class="mt-2">Do you use mouthwash?</label><br />
                <input type="radio" id="mouthwashYes" name="mouthwash" value="Yes">
                <label for="mouthwashYes" class="mt-2 me-3">Yes</label>
                <input type="radio" id="mouthwashNo" name="mouthwash" value="No">
                <label for="mouthwashNo">No</label>

                <div class="form-group mt-2">
                    <label for="reasonForVisit" class="mt-2">Reason for Visit:</label>
                    <select class="form-control mt-2" id="reasonForVisit">
                        <option value="">-- Select Reason --</option>
                        <option value="routineCleaning">Routine Cleaning</option>
                        <option value="toothPain">Tooth Pain</option>
                        <option value="bleedingGums">Bleeding Gums</option>
                        <option value="sensitivity">Sensitivity</option>
                        <option value="followUp">Follow-Up</option>
                        <option value="other">Other</option>
                    </select>
                </div>    

                <div class="form-group mt-2">
                    <label for="teethCondition">Teeth Condition:</label><br />
                    <input type="checkbox" id="healthy" value="healthy">
                    <label for="healthy" class="mt-2 me-3">Healthy</label><br />
                    <input type="checkbox" id="cavities" value="cavities">
                    <label for="cavities">Cavities</label><br />
                    <input type="checkbox" id="missingTeeth" value="missingTeeth">
                    <label for="missingTeeth">Missing Teeth</label><br />
                    <input type="checkbox" id="cracks" value="cracks">
                    <label for="cracks">Cracks</label><br />
                    <input type="checkbox" id="discoloration" value="discoloration">
                    <label for="discoloration">Discoloration</label><br />
                    <input type="checkbox" id="wearOrErosion" value="wearOrErosion">
                    <label for="wearOrErosion">Wear or Erosion</label><br />
                    <input type="checkbox" id="otherTeethCondition" value="other">
                    <label for="otherTeethCondition">Other</label>
                    <input type="text" id="otherTeethConditionDetails" placeholder="Specify other condition" style="display: none;">
                </div>

                <div class="form-group mt-2">
                    <label for="gumsCondition" class="mt-2">Gums Condition:</label>
                    <select class="form-control mt-2" id="gumsCondition">
                        <option value="">-- Select Condition --</option>
                        <option value="healthy">Healthy</option>
                        <option value="swollen">Swollen</option>
                        <option value="bleeding">Bleeding</option>
                        <option value="receding">Receding</option>
                        <option value="other">Other</option>
                    </select>
                </div>

                <div class="form-group mt-2">
                    <label for="oralTissues">Oral Tissues:</label>
                    <select class="form-control mt-2" id="oralTissues">
                        <option value="">-- Select Finding --</option>
                        <option value="healthy">Healthy</option>
                        <option value="sores">Sores</option>
                        <option value="lesions">Lesions</option>
                        <option value="ulcers">Ulcers</option>
                        <option value="other">Other</option>
                    </select>
                </div>    

                <div class="form-group mt-2">
                    <label for="existingRestorations">Existing Restorations:</label>
                    <select id="existingRestorations" class="form-control mt-2">
                        <option value="" disabled selected>--Select restorations--</option>
                        <option value="filling">Filling</option>
                        <option value="crown">Crown</option>
                        <option value="bridge">Bridge</option>
                        <option value="veneer">Veneer</option>
                        <option value="implant">Implant</option>
                        <option value="inlay-onlay">Inlay/Onlay</option>
                        <option value="partialDenture">Partial Denture</option>
                        <option value="fullDenture">Full Denture</option>
                        <option value="other">Other (Specify Below)</option>
                    </select>
                </div>
                <input type="text" id="otherRestoration" class="form-control" placeholder="Specify other restoration (if any)" style="display: none;" />                

                <div class="form-group mt-2">
                    <label>Treatments</label>
                    <select class="form-control mt-2" id="treatmentDetails">
                        <option value="" disabled selected>--Select treatment--</option>
                        <option value="scalingPolishing">Scaling & Polishing</option>
                        <option value="filling">Filling</option>
                        <option value="extraction">Extraction</option>
                        <option value="rootCanal">Root Canal</option>
                        <option value="crownPlacement">Crown Placement</option>
                        <option value="gumTreatment">Gum Treatment</option>
                        <option value="other">Other</option>
                    </select>
                </div>

                <div class="form-group mt-2">
                    <label>Preventive Care</label>
                    <select class="form-control mt-2" id="preventiveCare">
                        <option value="" disabled selected>--Select preventive care--</option>
                        <option value="fluorideTreatment">Fluoride Treatment</option>
                        <option value="sealants">Sealants</option>
                        <option value="oralHygieneInstruction">Oral Hygiene Instruction</option>
                        <option value="dietaryAdvice">Dietary Advice</option>
                    </select>
                </div>

                <label class="mt-2">Follow-up and Recommendations</label>
                <textarea id="followupPlan" rows="3" class="form-control mt-2" placeholder="Describe follow-up actions or recommendations"></textarea>

                <div class="mt-5 mb-5 flexend">
                    <button class="btn btn-primary" onclick="submitDentalHistory()">Submit Dental History</button>
                </div>
            </div>      
        </div>
    </div>

    <!-- Dental Records Section -->
    <div id="dentalRecordsSection" class="content-section" style="display: none;">
        <h1 class="mt-5">Dental Checkup Records</h1>
        
        <input type="text" id="searchCheckup" placeholder="Search Dental Checkup Records" onkeyup="searchCheckups()" style="width: 20%;">
    
        <div class="table-container">
            <table class="table table-striped">
                <thead>
                    <tr>
                        <th>Form ID</th>
                        <th>Patient Name</th>
                        <th>Treatment</th>  
                    </tr>
                </thead>
                <tbody id="dentalCheckupListData"></tbody>
            </table>
        </div>
        
        <div class="pagination">
            <button id="prevPage" class="btn btn-secondary" onclick="prevPage()">Previous</button>
            <span id="pageInfo">Page 1</span>
            <button id="nextPage" class="btn btn-secondary" onclick="nextPage()">Next</button>
        </div>
    </div>
    
    <!-- Dental Checkup Modal -->
    <div id="dentalCheckupModal" class="modal" style="display: none;">
        <div class="modal-content">
            <span class="close" onclick="closeModal()">&times;</span>
            <h2>Patient Record</h2>
            <div id="modalContent"></div>
        </div>
    </div>

    <!-- Time In/Out Section -->
    <div id="timeInOutSection" class="content-section" style="display: none;">
        <h5 class="mt-3">Time In/Time Out</h5>

        <div class="d-flex mt-2">
            <button id="timeInButton" class="btn btn-primary me-2" onclick="timeIn()">Time In</button>
            <button id="timeOutButton" class="btn btn-primary" onclick="timeOut()">Time Out</button>
        </div>

        <div id="timeRecordsDisplay" class="mt-3 text-center"></div>

        <div class="table-container mt-3">
            <table id="timeRecordsTable" class="table table-striped">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Time In</th>
                        <th>Time Out</th>
                        <th>Total Hours</th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
    </div>
</div>

<!-- Logout Modal -->
<div id="logoutModal" class="modal" style="display: none;">
    <div class="modal-content">
        <span class="close" id="cancelLogout">&times;</span>  
        <h2>Confirm Logout</h2>
        <p>Are you sure you want to logout?</p>
        <div class="modal-actions flexend">
            <button id="confirmLogout" class="btn btn-danger">Logout</button>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/limonte-sweetalert2/7.2.0/sweetalert2.all.min.js"></script>
<script>
// Global variables
let allDentalCheckups = [];
let filteredDentalCheckups = [];
let currentPage = 1;
const rowsPerPage = 10;
let selectedDate = new Date();
const monthNames = [
    "January", "February", "March", "April", "May", "June",
    "July", "August", "September", "October", "November", "December"
];

// ==================== UTILITY FUNCTIONS ====================

function showSection(sectionId) {
    document.querySelectorAll('.content-section').forEach(section => {
        section.style.display = 'none';
    });
    document.getElementById(sectionId).style.display = 'block';
    
    // Load data for the section if needed
    switch(sectionId) {
        case 'dashboardSection':
            fetchDashboardData();
            break;
        case 'appointmentSection':
            populateYearDropdown();
            updateMonthYearDisplay();
            generateCalendarDays();
            break;
        case 'dentalRecordsSection':
            fetchDentalCheckupData();
            break;
        case 'timeInOutSection':
            fetchTimeRecords();
            break;
    }
}

function toggleUserMenu() {
    const userMenu = document.getElementById('userMenu');
    userMenu.style.display = userMenu.style.display === 'none' ? 'block' : 'none';
}

function showLogoutModal() {
    document.getElementById('logoutModal').style.display = 'flex';
}

function closeLogoutModal() {
    document.getElementById('logoutModal').style.display = 'none';
}

function closeModal() {
    document.getElementById('dentalCheckupModal').style.display = 'none';
}

// ==================== APPOINTMENT FUNCTIONS ====================

function fetchDashboardData() {
    const date = document.getElementById('appointmentDatePicker').value;
    fetch(`dental.php?action=get_dashboard_data&date=${date}`)
        .then(response => response.json())
        .then(data => {
            document.getElementById('totalAppointments').textContent = data.total || 0;
            document.getElementById('pendingAppointments').textContent = data.pending || 0;
            document.getElementById('completedAppointments').textContent = data.completed || 0;
            document.getElementById('canceledAppointments').textContent = data.canceled || 0;
        })
        .catch(error => console.error('Error:', error));
}

function populateYearDropdown() {
    const yearSelect = document.getElementById('yearSelect');
    yearSelect.innerHTML = '';
    const currentYear = new Date().getFullYear();
    
    for (let year = currentYear - 5; year <= currentYear + 5; year++) {
        const option = document.createElement('option');
        option.value = year;
        option.textContent = year;
        yearSelect.appendChild(option);
    }
    yearSelect.value = currentYear;
}

function updateMonthYearDisplay() {
    document.getElementById('currentMonth').textContent = 
        `${monthNames[selectedDate.getMonth()]} ${selectedDate.getFullYear()}`;
}

function generateCalendarDays() {
    const calendarDays = document.getElementById('calendarDays');
    calendarDays.innerHTML = '';
    
    const year = selectedDate.getFullYear();
    const month = selectedDate.getMonth();
    const daysInMonth = new Date(year, month + 1, 0).getDate();
    
    for (let day = 1; day <= daysInMonth; day++) {
        const dayElement = document.createElement('div');
        dayElement.className = 'calendar-day';
        dayElement.textContent = day;
        
        const formattedDate = `${month + 1}/${day}/${year}`;
        dayElement.dataset.date = formattedDate;
        
        dayElement.addEventListener('click', () => {
            document.querySelectorAll('.calendar-day').forEach(d => d.classList.remove('selected'));
            dayElement.classList.add('selected');
            fetchAppointmentsForDate(formattedDate);
        });
        
        calendarDays.appendChild(dayElement);
    }
}

function fetchAppointmentsForDate(date) {
    fetch(`dental.php?action=get_appointments&date=${date}`)
        .then(response => response.json())
        .then(appointments => {
            const appointmentList = document.getElementById('appointmentList');
            appointmentList.innerHTML = '';
            
            if (appointments.length > 0) {
                appointments.forEach(appt => {
                    const listItem = document.createElement('li');
                    listItem.className = 'appointment-item';
                    listItem.innerHTML = `
                        ${appt.appointment_time} - ${appt.health_service} with ${appt.healthcare_provider} (${appt.status})
                        <div class="mt-2">
                            <button class="btn btn-success btn-sm" onclick="updateAppointmentStatus(${appt.id}, 'COMPLETED')">Complete</button>
                            <button class="btn btn-danger btn-sm" onclick="updateAppointmentStatus(${appt.id}, 'CANCELED')">Cancel</button>
                        </div>
                    `;
                    appointmentList.appendChild(listItem);
                });
            } else {
                const listItem = document.createElement('li');
                listItem.className = 'appointment-item';
                listItem.textContent = 'No appointments scheduled for this date.';
                appointmentList.appendChild(listItem);
            }
        })
        .catch(error => console.error('Error:', error));
}

function updateAppointmentStatus(appointmentId, status) {
    const formData = new FormData();
    formData.append('id', appointmentId);
    formData.append('status', status);
    
    fetch('dental.php?action=update_appointment', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            Swal.fire('Success', `Appointment marked as ${status}`, 'success');
            const selectedDate = document.querySelector('.calendar-day.selected')?.dataset.date;
            if (selectedDate) fetchAppointmentsForDate(selectedDate);
            fetchDashboardData();
        } else {
            Swal.fire('Error', 'Failed to update appointment', 'error');
        }
    })
    .catch(error => console.error('Error:', error));
}

document.getElementById('prevMonth').addEventListener('click', () => {
    selectedDate.setMonth(selectedDate.getMonth() - 1);
    updateMonthYearDisplay();
    generateCalendarDays();
});

document.getElementById('nextMonth').addEventListener('click', () => {
    selectedDate.setMonth(selectedDate.getMonth() + 1);
    updateMonthYearDisplay();
    generateCalendarDays();
});

document.getElementById('yearSelect').addEventListener('change', (e) => {
    selectedDate.setFullYear(parseInt(e.target.value));
    updateMonthYearDisplay();
    generateCalendarDays();
});

document.getElementById('appointmentDatePicker').addEventListener('change', (e) => {
    fetchDashboardData();
});

// ==================== PATIENT FUNCTIONS ====================

function fetchPatientData() {
    const patientId = document.getElementById('patientIdInput').value.trim();
    
    if (!patientId) {
        Swal.fire('Warning', 'Please enter a valid Resident ID', 'warning');
        return;
    }
    
    fetch(`dental.php?action=get_patient&patientId=${patientId}`)
        .then(response => response.json())
        .then(patient => {
            if (patient.error) {
                Swal.fire('Warning', patient.error, 'warning');
                return;
            }
            
            document.getElementById('patientId').textContent = patientId;
            document.getElementById('patientName').textContent = 
                `${patient.first_name} ${patient.middle_name || ''} ${patient.last_name}`;
            document.getElementById('patientAge').textContent = patient.age || 'N/A';
            document.getElementById('patientSex').textContent = patient.sex || 'N/A';
            document.getElementById('patientAddress').textContent = patient.address || 'N/A';
            
            document.getElementById('patientDataSection').style.display = 'block';
        })
        .catch(error => {
            console.error('Error:', error);
            Swal.fire('Error', 'Failed to fetch patient data', 'error');
        });
}

// ==================== DENTAL HISTORY FUNCTIONS ====================

function toggleTextInput(radioGroupName, textInputId) {
    const radios = document.getElementsByName(radioGroupName);
    let isNoSelected = false;

    radios.forEach((radio) => {
        if (radio.checked && radio.value === "No") {
            isNoSelected = true;
        }
    });

    const textInput = document.getElementById(textInputId);
    textInput.disabled = isNoSelected;
    if (isNoSelected) textInput.value = '';
}

document.getElementById('medicationYes').addEventListener('change', function() {
    toggleTextInput('medication', 'medicationDetails');
});
document.getElementById('medicationNo').addEventListener('change', function() {
    toggleTextInput('medication', 'medicationDetails');
});

document.getElementById('allergyYes').addEventListener('change', function() {
    toggleTextInput('allergy', 'allergyDetails');
});
document.getElementById('allergyNo').addEventListener('change', function() {
    toggleTextInput('allergy', 'allergyDetails');
});

function validateDentalHistoryForm() {
    const patientId = document.getElementById('patientId').textContent;
    const reasonForVisit = document.getElementById('reasonForVisit').value;
    const treatmentDetails = document.getElementById('treatmentDetails').value;
    
    if (!patientId || patientId === '') {
        Swal.fire('Warning', 'Please fetch patient data first', 'warning');
        return false;
    }
    
    if (!reasonForVisit || !treatmentDetails) {
        Swal.fire('Warning', 'Please fill out all required fields', 'warning');
        return false;
    }
    
    return true;
}

function submitDentalHistory() {
    if (!validateDentalHistoryForm()) return;

    // Gather all form data
    const patientId = document.getElementById('patientId').textContent;
    const patientName = document.getElementById('patientName').textContent;
    
    // Medical conditions
    const medicalConditions = [];
    if (document.getElementById('diabetes').checked) medicalConditions.push('Diabetes');
    if (document.getElementById('heartDisease').checked) medicalConditions.push('Heart Disease');
    if (document.getElementById('hypertension').checked) medicalConditions.push('Hypertension');
    if (document.getElementById('asthma').checked) medicalConditions.push('Asthma');
    if (document.getElementById('hiv').checked) medicalConditions.push('HIV/AIDS');
    if (document.getElementById('otherMedical').checked) {
        medicalConditions.push(`Other: ${document.getElementById('otherMedicalDetails').value}`);
    }
    
    // Medications
    const medications = document.querySelector('input[name="medication"]:checked')?.value || 'No';
    const medicationDetails = medications === 'Yes' ? document.getElementById('medicationDetails').value : '';
    
    // Allergies
    const allergies = document.querySelector('input[name="allergy"]:checked')?.value || 'No';
    const allergyDetails = allergies === 'Yes' ? document.getElementById('allergyDetails').value : '';
    
    // Previous treatment
    const previousTreatment = document.querySelector('input[name="previousTreatment"]:checked')?.value || 'No';
    const previousTreatmentDetails = document.getElementById('previousTreatmentDetails').value;
    
    // Symptoms
    const symptoms = [];
    if (document.getElementById('toothache').checked) symptoms.push('Toothache');
    if (document.getElementById('sensitivity').checked) symptoms.push('Sensitivity to hot or cold');
    if (document.getElementById('bleedingGums').checked) symptoms.push('Bleeding gums');
    if (document.getElementById('badBreath').checked) symptoms.push('Bad breath');
    if (document.getElementById('swollenGums').checked) symptoms.push('Swollen gums');
    if (document.getElementById('looseTeeth').checked) symptoms.push('Loose teeth');
    if (document.getElementById('otherSymptoms').checked) {
        symptoms.push(`Other: ${document.getElementById('otherSymptomsDetails').value}`);
    }
    
    // Oral hygiene
    const brushingFrequency = document.querySelector('input[name="brushFrequency"]:checked')?.value || 'Not specified';
    const flossing = document.querySelector('input[name="floss"]:checked')?.value || 'Not specified';
    const mouthwash = document.querySelector('input[name="mouthwash"]:checked')?.value || 'Not specified';
    
    // Dental examination
    const reasonForVisit = document.getElementById('reasonForVisit').value;
    const treatmentDetails = document.getElementById('treatmentDetails').value;
    const preventiveCare = document.getElementById('preventiveCare').value;
    const gumsCondition = document.getElementById('gumsCondition').value;
    const oralTissues = document.getElementById('oralTissues').value;
    const followupPlan = document.getElementById('followupPlan').value;
    
    // Generate form ID
    const formId = `DENT-${patientId}-${Date.now()}`;
    
    // Prepare data for submission
    const dentalHistoryData = {
        patientId,
        patientName,
        reasonForVisit,
        treatmentDetails,
        preventiveCare,
        gumsCondition,
        oralTissues,
        medicalConditions,
        medications,
        medicationDetails,
        allergies,
        allergyDetails,
        previousTreatment,
        previousTreatmentDetails,
        symptoms,
        followupPlan,
        brushingFrequency,
        flossing,
        mouthwash,
        formId
    };
    
    // Submit to server
    fetch('dental.php?action=submit_dental_history', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify(dentalHistoryData)
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            Swal.fire('Success', `Dental history submitted successfully! Form ID: ${data.formId}`, 'success');
            clearDentalHistoryForm();
        } else {
            Swal.fire('Error', 'Failed to submit dental history', 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        Swal.fire('Error', 'Failed to submit dental history', 'error');
    });
}

function clearDentalHistoryForm() {
    // Clear all checkboxes
    document.querySelectorAll('input[type="checkbox"]').forEach(checkbox => {
        checkbox.checked = false;
    });
    
    // Clear all radio buttons
    document.querySelectorAll('input[type="radio"]').forEach(radio => {
        radio.checked = false;
    });
    
    // Clear all text inputs and textareas
    document.querySelectorAll('input[type="text"], textarea').forEach(input => {
        input.value = '';
    });
    
    // Reset all selects
    document.querySelectorAll('select').forEach(select => {
        select.selectedIndex = 0;
    });
    
    // Hide patient data section
    document.getElementById('patientDataSection').style.display = 'none';
    document.getElementById('patientIdInput').value = '';
}

// ==================== DENTAL RECORDS FUNCTIONS ====================

function fetchDentalCheckupData() {
    fetch(`dental.php?action=get_dental_records&page=${currentPage}`)
        .then(response => response.json())
        .then(data => {
            allDentalCheckups = data.records;
            filteredDentalCheckups = [...allDentalCheckups];
            displayDentalCheckups(data.total, data.pages);
        })
        .catch(error => {
            console.error('Error:', error);
            Swal.fire('Error', 'Failed to load dental records', 'error');
        });
}

function displayDentalCheckups(total, totalPages) {
    const tableBody = document.getElementById('dentalCheckupListData');
    tableBody.innerHTML = '';
    
    if (filteredDentalCheckups.length > 0) {
        filteredDentalCheckups.forEach(record => {
            const row = document.createElement('tr');
            row.innerHTML = `
                <td>${record.form_id}</td>
                <td>${record.patient_name}</td>
                <td>${record.treatment_details}</td>
            `;
            row.addEventListener('click', () => openDentalRecordModal(record.form_id));
            tableBody.appendChild(row);
        });
    } else {
        const row = document.createElement('tr');
        row.innerHTML = '<td colspan="3">No dental records found</td>';
        tableBody.appendChild(row);
    }
    
    document.getElementById('pageInfo').textContent = `Page ${currentPage} of ${totalPages}`;
    document.getElementById('prevPage').disabled = currentPage === 1;
    document.getElementById('nextPage').disabled = currentPage >= totalPages;
}

function openDentalRecordModal(formId) {
    fetch(`dental.php?action=get_dental_record&formId=${formId}`)
        .then(response => response.json())
        .then(record => {
            if (record.error) {
                Swal.fire('Error', record.error, 'error');
                return;
            }
            
            const modalContent = document.getElementById('modalContent');
            modalContent.innerHTML = `
                <div style="display: flex; flex-wrap: wrap; gap: 20px;">
                    <div style="flex: 1; min-width: 250px;">
                        <p><strong>Form ID:</strong> ${record.form_id}</p>
                        <p><strong>Patient ID:</strong> ${record.patient_id}</p>
                        <p><strong>Patient Name:</strong> ${record.patient_name}</p>
                        <p><strong>Reason for Visit:</strong> ${record.reason_for_visit}</p>
                        <p><strong>Treatment Details:</strong> ${record.treatment_details}</p>
                        <p><strong>Preventive Care:</strong> ${record.preventive_care}</p>
                        <p><strong>Gums Condition:</strong> ${record.gums_condition}</p>
                        <p><strong>Oral Tissues:</strong> ${record.oral_tissues}</p>
                        <p><strong>Brushing Frequency:</strong> ${record.brushing_frequency}</p>
                        <p><strong>Flossing:</strong> ${record.flossing}</p>
                        <p><strong>Mouthwash:</strong> ${record.mouthwash}</p>
                    </div>
                    <div style="flex: 1; min-width: 250px;">
                        <p><strong>Medical Conditions:</strong> ${record.medical_conditions.join(', ')}</p>
                        <p><strong>Medications:</strong> ${record.medications}</p>
                        <p><strong>Medication Details:</strong> ${record.medication_details}</p>
                        <p><strong>Allergies:</strong> ${record.allergies}</p>
                        <p><strong>Allergy Details:</strong> ${record.allergy_details}</p>
                        <p><strong>Previous Treatment:</strong> ${record.previous_treatment}</p>
                        <p><strong>Previous Treatment Details:</strong> ${record.previous_treatment_details}</p>
                        <p><strong>Symptoms:</strong> ${record.symptoms.join(', ')}</p>
                        <p><strong>Follow-up Plan:</strong> ${record.followup_plan}</p>
                    </div>
                </div>
            `;
            
            document.getElementById('dentalCheckupModal').style.display = 'flex';
        })
        .catch(error => {
            console.error('Error:', error);
            Swal.fire('Error', 'Failed to load dental record details', 'error');
        });
}

function searchCheckups() {
    const searchTerm = document.getElementById('searchCheckup').value.toLowerCase();
    
    if (searchTerm.trim() === '') {
        filteredDentalCheckups = [...allDentalCheckups];
    } else {
        filteredDentalCheckups = allDentalCheckups.filter(record => {
            return (
                record.form_id.toLowerCase().includes(searchTerm) ||
                record.patient_name.toLowerCase().includes(searchTerm) ||
                record.treatment_details.toLowerCase().includes(searchTerm)
            );
        });
    }
    
    currentPage = 1;
    fetchDentalCheckupData();
}

function prevPage() {
    if (currentPage > 1) {
        currentPage--;
        fetchDentalCheckupData();
    }
}

function nextPage() {
    currentPage++;
    fetchDentalCheckupData();
}

// ==================== TIME IN/OUT FUNCTIONS ====================

function timeIn() {
    fetch('dental.php?action=time_in_out&type=in')
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                Swal.fire('Success', 'Time in recorded', 'success');
                fetchTimeRecords();
            } else {
                Swal.fire('Error', data.message || 'Failed to record time in', 'error');
            }
        })
        .catch(error => console.error('Error:', error));
}

function timeOut() {
    fetch('dental.php?action=time_in_out&type=out')
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                Swal.fire('Success', 'Time out recorded', 'success');
                fetchTimeRecords();
            } else {
                Swal.fire('Error', data.message || 'Failed to record time out', 'error');
            }
        })
        .catch(error => console.error('Error:', error));
}

function fetchTimeRecords() {
    fetch('dental.php?action=get_time_records')
        .then(response => response.json())
        .then(records => {
            const tableBody = document.querySelector('#timeRecordsTable tbody');
            tableBody.innerHTML = '';
            
            if (records.length > 0) {
                records.forEach(record => {
                    const row = document.createElement('tr');
                    row.innerHTML = `
                        <td>${record.date}</td>
                        <td>${record.time_in}</td>
                        <td>${record.time_out || '-'}</td>
                        <td>${record.time_out ? calculateHours(record.time_in, record.time_out) : '-'}</td>
                    `;
                    tableBody.appendChild(row);
                });
            } else {
                const row = document.createElement('tr');
                row.innerHTML = '<td colspan="4">No time records found</td>';
                tableBody.appendChild(row);
            }
        })
        .catch(error => console.error('Error:', error));
}

function calculateHours(timeIn, timeOut) {
    const [inHours, inMinutes] = timeIn.split(':').map(Number);
    const [outHours, outMinutes] = timeOut.split(':').map(Number);
    
    const totalMinutes = (outHours * 60 + outMinutes) - (inHours * 60 + inMinutes);
    const hours = Math.floor(totalMinutes / 60);
    const minutes = totalMinutes % 60;
    
    return `${hours}h ${minutes}m`;
}


document.addEventListener('DOMContentLoaded', function() {
    // Set up logout button
    document.getElementById('confirmLogout').addEventListener('click', () => {
        window.location.href = 'login.php';
    });
    
    document.getElementById('cancelLogout').addEventListener('click', closeLogoutModal);
    
    // Initialize dashboard
    showSection('dashboardSection');
    
    // Set current date in date picker
    document.getElementById('appointmentDatePicker').valueAsDate = new Date();
    
    // Initialize form event listeners
    document.getElementById('medicationYes').addEventListener('change', function() {
        toggleTextInput('medication', 'medicationDetails');
    });
    document.getElementById('medicationNo').addEventListener('change', function() {
        toggleTextInput('medication', 'medicationDetails');
    });
    
    document.getElementById('allergyYes').addEventListener('change', function() {
        toggleTextInput('allergy', 'allergyDetails');
    });
    document.getElementById('allergyNo').addEventListener('change', function() {
        toggleTextInput('allergy', 'allergyDetails');
    });
    
    // Close modal when clicking outside
    document.getElementById('dentalCheckupModal').addEventListener('click', function(e) {
        if (e.target === this) {
            closeModal();
        }
    });
});
</script>
</body>
</html>