<?php
session_start();
include 'config.php';

// Verify user is logged in and has the correct role
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

if ($_SESSION['role'] !== 'midwife') {
    header("Location: midwife.php");
    exit();
}

// Handle API requests
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    
    try {
        switch ($_GET['action']) {
            case 'get_pregnant_women':
                $stmt = $conn->prepare("SELECT * FROM pregnant_women");
                $stmt->execute();
                $women = $stmt->fetchAll(PDO::FETCH_ASSOC);
                echo json_encode($women);
                break;
                
            case 'get_appointments':
                $date = $_GET['date'] ?? date('Y-m-d');
                $formattedDate = date('m/d/Y', strtotime($date));
                
                $stmt = $conn->prepare("SELECT * FROM appointments WHERE appointment_date = ? AND healthcare_provider = 'Midwife'");
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
                    WHERE appointment_date = ? AND healthcare_provider = 'Midwife'");
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
                
            case 'submit_prenatal':
                $data = json_decode(file_get_contents('php://input'), true);
                
                $stmt = $conn->prepare("INSERT INTO prenatal_care (
                    patient_id, blood_pressure, weight, height, fundal_height, 
                    fetal_heart_tone, current_gestation, nausea, back_pain, 
                    fatigue, swelling, headaches, smoking, alcohol, caffeine, 
                    exercise, pregnancy_weeks, due_date, high_blood_pressure, 
                    diabetes, thyroid_disorder, heart_disease, kidney_issues, 
                    asthma, arthritis, cancer, other_conditions, form_id
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                
                $stmt->execute([
                    $data['patientId'],
                    $data['bloodPressure'],
                    $data['weight'],
                    $data['height'],
                    $data['fundalHeight'],
                    $data['fetalHeartTone'],
                    $data['currentGestation'],
                    $data['nausea'],
                    $data['backPain'],
                    $data['fatigue'],
                    $data['swelling'],
                    $data['headaches'],
                    $data['smoking'],
                    $data['alcohol'],
                    $data['caffeine'],
                    $data['exercise'],
                    $data['pregnancyWeeks'],
                    $data['dueDate'],
                    $data['highBloodPressure'],
                    $data['diabetes'],
                    $data['thyroidDisorder'],
                    $data['heartDisease'],
                    $data['kidneyIssues'],
                    $data['asthma'],
                    $data['arthritis'],
                    $data['cancer'],
                    $data['otherConditions'],
                    $data['formId']
                ]);
                
                echo json_encode(['success' => true, 'formId' => $data['formId']]);
                break;
                
            case 'submit_family_plan':
                $data = json_decode(file_get_contents('php://input'), true);
                
                $stmt = $conn->prepare("INSERT INTO family_planning (
                    patient_id, num_children, blood_pressure, weight, gravida, 
                    para, lmp, menstrual_cycle, hypertension, diabetes, 
                    heart_disease, allergies, other_medical, contraceptive_use, 
                    contraceptive_method, first_time_user, switching_methods, 
                    counseling, natural_family_planning, barrier_methods, 
                    hormonal_methods, iud, permanent_methods, others_method, 
                    proper_use, side_effects, importance_follow_up, form_id
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                
                $stmt->execute([
                    $data['patientId'],
                    $data['numChildren'],
                    $data['bloodPressure'],
                    $data['weight'],
                    $data['gravida'],
                    $data['para'],
                    $data['lmp'],
                    $data['menstrualCycle'],
                    $data['hypertension'],
                    $data['diabetes'],
                    $data['heartDisease'],
                    $data['allergies'],
                    $data['otherMedical'],
                    $data['contraceptiveUse'],
                    $data['contraceptiveMethod'],
                    $data['firstTimeUser'],
                    $data['switchingMethods'],
                    $data['counseling'],
                    $data['naturalFamilyPlanning'],
                    $data['barrierMethods'],
                    $data['hormonalMethods'],
                    $data['iud'],
                    $data['permanentMethods'],
                    $data['othersMethod'],
                    $data['properUse'],
                    $data['sideEffects'],
                    $data['importanceFollowUp'],
                    $data['formId']
                ]);
                
                echo json_encode(['success' => true, 'formId' => $data['formId']]);
                break;
                
            case 'get_prenatal_records':
                $page = $_GET['page'] ?? 1;
                $search = $_GET['search'] ?? '';
                $limit = 10;
                $offset = ($page - 1) * $limit;
                
                if ($search) {
                    $stmt = $conn->prepare("SELECT * FROM prenatal_care 
                                          WHERE patient_id LIKE ? OR form_id LIKE ?
                                          ORDER BY id DESC LIMIT ? OFFSET ?");
                    $searchTerm = "%$search%";
                    $stmt->execute([$searchTerm, $searchTerm, $limit, $offset]);
                    $countStmt = $conn->prepare("SELECT COUNT(*) FROM prenatal_care 
                                               WHERE patient_id LIKE ? OR form_id LIKE ?");
                    $countStmt->execute([$searchTerm, $searchTerm]);
                } else {
                    $stmt = $conn->prepare("SELECT * FROM prenatal_care 
                                          ORDER BY id DESC LIMIT ? OFFSET ?");
                    $stmt->execute([$limit, $offset]);
                    $countStmt = $conn->prepare("SELECT COUNT(*) FROM prenatal_care");
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
                
            case 'get_family_plan_records':
                $page = $_GET['page'] ?? 1;
                $search = $_GET['search'] ?? '';
                $limit = 10;
                $offset = ($page - 1) * $limit;
                
                if ($search) {
                    $stmt = $conn->prepare("SELECT * FROM family_planning 
                                          WHERE patient_id LIKE ? OR form_id LIKE ?
                                          ORDER BY id DESC LIMIT ? OFFSET ?");
                    $searchTerm = "%$search%";
                    $stmt->execute([$searchTerm, $searchTerm, $limit, $offset]);
                    $countStmt = $conn->prepare("SELECT COUNT(*) FROM family_planning 
                                               WHERE patient_id LIKE ? OR form_id LIKE ?");
                    $countStmt->execute([$searchTerm, $searchTerm]);
                } else {
                    $stmt = $conn->prepare("SELECT * FROM family_planning 
                                          ORDER BY id DESC LIMIT ? OFFSET ?");
                    $stmt->execute([$limit, $offset]);
                    $countStmt = $conn->prepare("SELECT COUNT(*) FROM family_planning");
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
                
            case 'get_prenatal_record':
                $formId = $_GET['formId'];
                $stmt = $conn->prepare("SELECT * FROM prenatal_care WHERE form_id = ?");
                $stmt->execute([$formId]);
                $record = $stmt->fetch(PDO::FETCH_ASSOC);
                echo json_encode($record ?: ['error' => 'Record not found']);
                break;
                
            case 'get_family_plan_record':
                $formId = $_GET['formId'];
                $stmt = $conn->prepare("SELECT * FROM family_planning WHERE form_id = ?");
                $stmt->execute([$formId]);
                $record = $stmt->fetch(PDO::FETCH_ASSOC);
                echo json_encode($record ?: ['error' => 'Record not found']);
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
        /* All your existing CSS styles from midwife.html */
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
            <a class="nav-link d-flex align-items-center" href="#" onclick="showSection('patientSection')">
                <i class="fa-solid fa-house-medical mr-2"></i> 
                Patient
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link d-flex align-items-center" href="#" onclick="showSection('appointmentSection')">
                <i class="fa-solid fa-phone mr-2"></i> 
                Appointment
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link d-flex align-items-center" href="#" onclick="showSection('PreNatalSection')">
                <i class="fa-solid fa-heart"></i>
                Prenatal Care
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link d-flex align-items-center" href="#" onclick="showSection('FamilyPlanSection')">
                <i class="fa-solid fa-users mr-2"></i> 
                Family Planning
            </a>
        </li>
    </ul>
</div>

<!-- Main Content -->
<div class="content">
    <!-- Dashboard Section -->
    <div id="dashboardSection" class="content-section">
        <h1 class="mt-5">Midwife Appointment Dashboard</h1>
        
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

    <!-- Patient Section -->
    <div id="patientSection" class="content-section" style="display: none;">
        <h1 class="mt-5">Patient List</h1>
        <div class="table-responsive">
            <table class="table table-striped">
                <thead>
                    <tr>
                        <th>Patient ID</th>
                        <th>Form ID</th>
                        <th>Timestamp</th>
                        <th>Diabetes</th>
                        <th>Heart Disease</th>
                        <th>Hypertension</th>
                        <th>Gravida</th>
                        <th>Para</th>
                        <th>Number of Children</th>
                        <th>Last Menstrual Period (LMP)</th>
                        <th>Menstrual Cycle</th>
                        <th>Preferred Methods</th>
                        <th>Counseling Provided</th>
                        <th>Other Medical History</th>
                        <th>Weight (kg)</th>
                    </tr>
                </thead>
                <tbody id="patientListData">
                    <!-- Data will be populated by JavaScript -->
                </tbody>
            </table>
        </div>
    </div>

    <!-- Appointment Section -->
    <div id="appointmentSection" class="content-section" style="display: none;">
        <h1 class="mt-5">Midwife Appointment Calendar</h1>
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

    <!-- Prenatal Care Section -->
    <div id="PreNatalSection" class="content-section" style="display: none;">
        <h1 class="mt-5">Pre-Natal Care</h1>

        <div>
            <label for="patientIdInput">Enter Resident ID:</label>
            <input type="text" style="width: 20%;" id="patientIdInput" placeholder="Resident ID" />
            <button class="btn btn-primary me-3" onclick="fetchPatientData()">Fetch Patient Data</button>
        </div>

        <div id="patientDataSection" style="display: none;">
            <h1 class="mt-5">Patient Information</h1>
            <p><strong>Resident ID:</strong> <span id="patientId"></span></p>
            <p><strong>Name:</strong> <span id="patientName"></span></p>
            <p><strong>Age:</strong> <span id="patientAge"></span></p>
            <p><strong>Sex:</strong> <span id="patientSex"></span></p>
            <p><strong>Address:</strong> <span id="patientAddress"></span></p>
        </div>

        <div class="row">
            <div class="col">
                <label for="bloodPressure">Blood Pressure:</label>
                <input type="text" id="bloodPressure" placeholder="Enter Blood Pressure" required />
                <br />
            
                <label for="weight">Weight:</label>
                <input type="number" id="weight" placeholder="Enter Weight" required />
                <br />
            
                <label for="height">Height:</label>
                <input type="number" id="height" placeholder="Enter Height" required />
                <br />
            
                <label for="fundalHeight">Fundal Height:</label>
                <input type="number" id="fundalHeight" placeholder="Enter Fundal Height" required />
                <br />
            
                <label for="fetalHeartTone">Fetal Heart Tone (FHT):</label>
                <input type="text" id="fetalHeartTone" placeholder="Enter Fetal Heart Tone" required />
                <br />

                <label>Have you been diagnosed with any of the following? (Check all that apply)</label><br />
                <input type="checkbox" id="highBloodPressure" /> High Blood Pressure<br />
                <input type="checkbox" id="diabetes" /> Diabetes<br />
                <input type="checkbox" id="thyroidDisorder" /> Thyroid Disorder<br />
                <input type="checkbox" id="heartDisease" /> Heart Disease<br />
                <input type="checkbox" id="kidneyIssues" /> Kidney Issues<br />
                <input type="checkbox" id="asthma" /> Asthma/Respiratory Disorders<br />
                <input type="checkbox" id="arthritis" /> Arthritis<br />
                <input type="checkbox" id="cancer" /> Cancer Type: <input type="text" id="cancerType" placeholder="Specify Type" /><br />
                <input type="checkbox" id="otherConditions" /> Other (Specify): <input type="text" id="otherConditionsDetails" placeholder="Specify Other Conditions" /><br />
                <br />
            </div>   
                     
            <div class="col">
                <label>Are you currently taking any medications?</label><br />
                <input type="radio" id="medicationsYes" name="medications" value="Yes" /> Yes
                <input type="radio" id="medicationsNo" name="medications" value="No" /> No<br />
                <label>If yes, list them:</label>
                <input type="text" id="medicationsList" placeholder="List Medications" /><br />
                <br />

                <label>Is this your first pregnancy?</label><br />
                <input type="radio" id="firstPregnancyYes" name="firstPregnancy" value="Yes" /> Yes
                <input type="radio" id="firstPregnancyNo" name="firstPregnancy" value="No" /> No<br />
                <label>If no, number of previous pregnancies:</label>
                <input type="number" id="previousPregnancies" placeholder="Enter Number of Previous Pregnancies" /><br />
                <br />
            
                <label>Any history of miscarriages or complications?</label><br />
                <input type="radio" id="miscarriagesYes" name="miscarriages" value="Yes" /> Yes
                <input type="radio" id="miscarriagesNo" name="miscarriages" value="No" /> No<br />
                <label>If yes, details:</label>
                <input type="text" id="miscarriageDetails" placeholder="Details of Miscarriages or Complications" /><br />
                <br />
            
                <label for="currentGestation">Current gestation (weeks):</label>
                <input type="number" id="currentGestation" placeholder="Enter Current Gestation Weeks" required />
                <br />
                <br />
            
                <label>Are you experiencing any of the following? (Check all that apply)</label><br />
                <input type="checkbox" id="nausea" /> Nausea/Vomiting<br />
                <input type="checkbox" id="backPain" /> Back Pain<br />
                <input type="checkbox" id="fatigue" /> Fatigue<br />
                <input type="checkbox" id="swelling" /> Swelling<br />
                <input type="checkbox" id="headaches" /> Headaches<br />
                <input type="checkbox" id="otherSymptoms" /> Other (Specify): <input type="text" id="otherSymptomsDetails" placeholder="Specify Other Symptoms" /><br />
                <br />
            </div>
            
            <div class="col">
                <label>Smoking:</label>
                <input type="radio" id="smokingYes" name="smoking" value="Yes" /> Yes
                <input type="radio" id="smokingNo" name="smoking" value="No" /> No | Frequency: <input type="text" id="smokingFrequency" placeholder="Frequency" /><br />
            
                <label>Alcohol:</label>
                <input type="radio" id="alcoholYes" name="alcohol" value="Yes" /> Yes
                <input type="radio" id="alcoholNo" name="alcohol" value="No" /> No | Frequency: <input type="text" id="alcoholFrequency" placeholder="Frequency" /><br />
            
                <label>Caffeine:</label>
                <input type="radio" id="caffeineYes" name="caffeine" value="Yes" /> Yes
                <input type="radio" id="caffeineNo" name="caffeine" value="No" /> No | Daily Amount: <input type="text" id="caffeineAmount" placeholder="Daily Amount" /><br />
            
                <label>Exercise:</label>
                <input type="radio" id="exerciseYes" name="exercise" value="Yes" /> Yes
                <input type="radio" id="exerciseNo" name="exercise" value="No" /> No | Type/Frequency: <input type="text" id="exerciseDetails" placeholder="Type/Frequency" /><br />
                <br />
            
                <label for="pregnancyWeeks">Weeks of Pregnancy:</label>
                <input type="number" id="pregnancyWeeks" placeholder="Enter weeks of pregnancy" required />
                <br />
        
                <label for="dueDate">Expected Due Date:</label>
                <input type="date" id="dueDate" required />
                <br />  
            </div>         
        <div class="mb-5 flexend">
            <button class="btn btn-primary" onclick="submitPrenatalDetails()">Submit Prenatal Records</button>
        </div>
    </div>

    <!-- Family Planning Section -->
    <div id="FamilyPlanSection" class="content-section" style="display: none;">
        <h1 class="mt-5">Family Planning</h1>
        <div>
            <label for="patientIdInputFamilyPlan">Enter Resident ID:</label>
            <input type="text" style="width: 20%;" id="patientIdInputFamilyPlan" placeholder="Resident ID" />
            <button class="btn btn-primary me-3" onclick="fetchFamilyplan()">Fetch Patient Data</button>
        </div>

        <div id="patientDataFamilyPlan" style="display: none;">
            <h1 class="mt-5">Patient Information</h1>
            <p><strong>Resident ID:</strong> <span id="patientIdFamilyPlan"></span></p>
            <p><strong>Name:</strong> <span id="patientNameFamilyPlan"></span></p>
            <p><strong>Age:</strong> <span id="patientAgeFamilyPlan"></span></p>
            <p><strong>Sex:</strong> <span id="patientSexFamilyPlan"></span></p>
            <p><strong>Address:</strong> <span id="patientAddressFamilyPlan"></span></p>
        </div>

        <div class="row">
            <div class="col">
                <label for="numChildren">Number of Children:</label>
                <input type="number" id="numChildren" placeholder="Enter Number of Children" required /><br />
                
                <label for="bloodPressure">Blood Pressure:</label>
                <input type="text" id="bloodPressure" placeholder="Enter Blood Pressure" required /><br />
                
                <label for="weight">Weight:</label>
                <input type="number" id="weight" placeholder="Enter Weight" required /><br />
                
                <label for="gravida">Gravida (No. of Pregnancies):</label>
                <input type="number" id="gravida" placeholder="Enter Number of Pregnancies" /><br />
            
                <label for="para">Para (No. of Deliveries):</label>
                <input type="number" id="para" placeholder="Enter Number of Deliveries" /><br />
            
                <label for="lmp">Last Menstrual Period (LMP):</label>
                <input type="date" id="lmp" placeholder="Enter LMP Date" /><br />
            
                <label for="menstrualCycle">Menstrual Cycle:</label><br />
                <input type="radio" id="regular" name="menstrualCycle" value="Regular" />
                <label for="regular">Regular</label>
                <input type="radio" id="irregular" name="menstrualCycle" value="Irregular" />
                <label for="irregular">Irregular</label><br />


                <input type="checkbox" id="hypertension" />
                <label for="hypertension">Hypertension:</label><br />

                <input type="checkbox" id="diabetes" />                       
                <label for="diabetes">Diabetes:</label><br /> 

                <input type="checkbox" id="heartDisease" />                        
                <label for="heartDisease">Heart Disease:</label><br />

                <label for="allergies">Allergies:</label>
                <input type="text" id="allergies" placeholder="Specify any allergies" /><br />
            
                <label for="otherMedical">Others (Specify):</label>
                <input type="text" id="otherMedical" placeholder="Specify other medical history" /><br />
            </div>

            <div class="col">    
                <label for="contraceptiveUse">Are you currently using a contraceptive method?</label><br />
                <input type="radio" id="contraceptiveYes" name="contraceptiveUse" value="Yes" />
                <label for="contraceptiveYes">Yes</label>
                <input type="radio" id="contraceptiveNo" name="contraceptiveUse" value="No" />
                <label for="contraceptiveNo">No</label><br />

                
                <label for="contraceptiveMethod">If yes, specify:</label>
                <input type="text" id="contraceptiveMethod" placeholder="Specify contraceptive method" /><br />
            
                <label for="consultationReason">Reason for Consultation:</label><br />
                <input type="checkbox" id="firstTimeUser" />
                <label for="firstTimeUser">First-time user</label><br />

                <input type="checkbox" id="switchingMethods" />                        
                <label for="switchingMethods">Switching methods</label><br />

                <input type="checkbox" id="counseling" />                     
                <label for="counseling">Counseling</label><br />

                <label for="preferredMethods">Preferred Method(s):</label><br />
                <input type="checkbox" id="naturalFamilyPlanning" />
                <label for="naturalFamilyPlanning">Natural Family Planning (e.g., Calendar/Rhythm Method)</label><br />

                <input type="checkbox" id="barrierMethods" />                     
                <label for="barrierMethods">Barrier Methods (e.g., Condoms)</label><br /> 

                <input type="checkbox" id="hormonalMethods" />
                <label for="hormonalMethods">Hormonal Methods (e.g., Pills, Injectables)</label><br />

                <input type="checkbox" id="IUD" />                      
                <label for="IUD">Intrauterine Device (IUD)</label><br />

                <input type="checkbox" id="permanentMethods" />                    
                <label for="permanentMethods">Permanent Methods (e.g., Tubal Ligation, Vasectomy)</label><br />  

            
                <label for="othersMethod">Others (Specify):</label>
                <input type="text" id="othersMethod" /><br />

                <input type="checkbox" id="methodAdvantages" />
                <label>Counseling and Education Provided:</label><br />

                <input type="checkbox" id="properUse" />                   
                <label for="properUse">Proper Use of Chosen Method</label><br />   

                <input type="checkbox" id="sideEffects" />                     
                <label for="sideEffects">Side Effects and Management</label><br /> 

                <input type="checkbox" id="importanceFollowUp" />                    
                <label for="importanceFollowUp">Importance of Follow-Up</label><br />  
            </div>    
            <div class="mb-5 flexend">
                <button class="btn btn-primary" onclick="submitFamilyPlan()">Submit Family Plan</button>
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
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/limonte-sweetalert2/7.2.0/sweetalert2.all.min.js"></script>
<script>
// Global variables
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
        case 'patientSection':
            fetchPatientRecords();
            break;
        case 'appointmentSection':
            populateYearDropdown();
            updateMonthYearDisplay();
            generateCalendarDays();
            break;
        case 'PreNatalSection':
        case 'FamilyPlanSection':
            // Initialize forms if needed
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

// ==================== APPOINTMENT FUNCTIONS ====================

function fetchDashboardData() {
    const date = document.getElementById('appointmentDatePicker').value;
    fetch(`midwife.php?action=get_dashboard_data&date=${date}`)
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
    fetch(`midwife.php?action=get_appointments&date=${date}`)
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
    
    fetch('midwife.php?action=update_appointment', {
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
    
    fetch(`midwife.php?action=get_patient&patientId=${patientId}`)
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

function fetchFamilyplan() {
    const patientId = document.getElementById('patientIdInputFamilyPlan').value.trim();
    
    if (!patientId) {
        Swal.fire('Warning', 'Please enter a valid Resident ID', 'warning');
        return;
    }
    
    fetch(`midwife.php?action=get_patient&patientId=${patientId}`)
        .then(response => response.json())
        .then(patient => {
            if (patient.error) {
                Swal.fire('Warning', patient.error, 'warning');
                return;
            }
            
            document.getElementById('patientIdFamilyPlan').textContent = patientId;
            document.getElementById('patientNameFamilyPlan').textContent = 
                `${patient.first_name} ${patient.middle_name || ''} ${patient.last_name}`;
            document.getElementById('patientAgeFamilyPlan').textContent = patient.age || 'N/A';
            document.getElementById('patientSexFamilyPlan').textContent = patient.sex || 'N/A';
            document.getElementById('patientAddressFamilyPlan').textContent = patient.address || 'N/A';
            
            document.getElementById('patientDataFamilyPlan').style.display = 'block';
        })
        .catch(error => {
            console.error('Error:', error);
            Swal.fire('Error', 'Failed to fetch patient data', 'error');
        });
}

function fetchPatientRecords() {
    fetch('midwife.php?action=get_family_plan_records')
        .then(response => response.json())
        .then(data => {
            const tableBody = document.getElementById('patientListData');
            tableBody.innerHTML = '';
            
            if (data.records && data.records.length > 0) {
                data.records.forEach(record => {
                    const row = document.createElement('tr');
                    row.innerHTML = `
                        <td>${record.patient_id || 'N/A'}</td>
                        <td>${record.form_id || 'N/A'}</td>
                        <td>${new Date(record.timestamp).toLocaleString() || 'N/A'}</td>
                        <td>${record.diabetes || 'N/A'}</td>
                        <td>${record.heart_disease || 'N/A'}</td>
                        <td>${record.hypertension || 'N/A'}</td>
                        <td>${record.gravida || 'N/A'}</td>
                        <td>${record.para || 'N/A'}</td>
                        <td>${record.num_children || 'N/A'}</td>
                        <td>${record.lmp || 'N/A'}</td>
                        <td>${record.menstrual_cycle || 'N/A'}</td>
                        <td>${record.preferred_methods || 'N/A'}</td>
                        <td>${record.counseling_provided || 'N/A'}</td>
                        <td>${record.other_medical || 'N/A'}</td>
                        <td>${record.weight || 'N/A'}</td>
                    `;
                    tableBody.appendChild(row);
                });
            } else {
                const row = document.createElement('tr');
                row.innerHTML = '<td colspan="15">No patient records found</td>';
                tableBody.appendChild(row);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            Swal.fire('Error', 'Failed to load patient records', 'error');
        });
}

// ==================== PRENATAL CARE FUNCTIONS ====================

function validatePrenatalForm() {
    const bloodPressure = document.getElementById('bloodPressure').value;
    const weight = document.getElementById('weight').value;
    const height = document.getElementById('height').value;
    const fundalHeight = document.getElementById('fundalHeight').value;
    const fetalHeartTone = document.getElementById('fetalHeartTone').value;
    const currentGestation = document.getElementById('currentGestation').value;
    const pregnancyWeeks = document.getElementById('pregnancyWeeks').value;
    const dueDate = document.getElementById('dueDate').value;

    if (!bloodPressure || !weight || !height || !fundalHeight || !fetalHeartTone || 
        !currentGestation || !pregnancyWeeks || !dueDate) {
        Swal.fire('Warning', 'Please fill out all required fields', 'warning');
        return false;
    }
    return true;
}

function submitPrenatalDetails() {
    if (!validatePrenatalForm()) return;

    const patientId = document.getElementById('patientId').textContent;
    const bloodPressure = document.getElementById('bloodPressure').value;
    const weight = document.getElementById('weight').value;
    const height = document.getElementById('height').value;
    const fundalHeight = document.getElementById('fundalHeight').value;
    const fetalHeartTone = document.getElementById('fetalHeartTone').value;
    const currentGestation = document.getElementById('currentGestation').value;
    const nausea = document.getElementById('nausea').checked ? 'Yes' : 'No';
    const backPain = document.getElementById('backPain').checked ? 'Yes' : 'No';
    const fatigue = document.getElementById('fatigue').checked ? 'Yes' : 'No';
    const swelling = document.getElementById('swelling').checked ? 'Yes' : 'No';
    const headaches = document.getElementById('headaches').checked ? 'Yes' : 'No';
    const smoking = document.getElementById('smokingYes').checked ? 'Yes' : 'No';
    const alcohol = document.getElementById('alcoholYes').checked ? 'Yes' : 'No';
    const caffeine = document.getElementById('caffeineYes').checked ? 'Yes' : 'No';
    const exercise = document.getElementById('exerciseYes').checked ? 'Yes' : 'No';
    const pregnancyWeeks = document.getElementById('pregnancyWeeks').value;
    const dueDate = document.getElementById('dueDate').value;
    const highBloodPressure = document.getElementById('highBloodPressure').checked ? 'Yes' : 'No';
    const diabetes = document.getElementById('diabetes').checked ? 'Yes' : 'No';
    const thyroidDisorder = document.getElementById('thyroidDisorder').checked ? 'Yes' : 'No';
    const heartDisease = document.getElementById('heartDisease').checked ? 'Yes' : 'No';
    const kidneyIssues = document.getElementById('kidneyIssues').checked ? 'Yes' : 'No';
    const asthma = document.getElementById('asthma').checked ? 'Yes' : 'No';
    const arthritis = document.getElementById('arthritis').checked ? 'Yes' : 'No';
    const cancer = document.getElementById('cancer').checked ? document.getElementById('cancerType').value : 'None';
    const otherConditions = document.getElementById('otherConditions').checked ? document.getElementById('otherConditionsDetails').value : 'None';

    const formId = `PN-${patientId}-${Date.now()}`;

    const formData = {
        patientId,
        bloodPressure,
        weight,
        height,
        fundalHeight,
        fetalHeartTone,
        currentGestation,
        nausea,
        backPain,
        fatigue,
        swelling,
        headaches,
        smoking,
        alcohol,
        caffeine,
        exercise,
        pregnancyWeeks,
        dueDate,
        highBloodPressure,
        diabetes,
        thyroidDisorder,
        heartDisease,
        kidneyIssues,
        asthma,
        arthritis,
        cancer,
        otherConditions,
        formId
    };

    fetch('midwife.php?action=submit_prenatal', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify(formData)
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            Swal.fire('Success', `Prenatal form submitted successfully! Form ID: ${data.formId}`, 'success');
            clearPrenatalForm();
        } else {
            Swal.fire('Error', 'Failed to submit prenatal form', 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        Swal.fire('Error', 'Failed to submit prenatal form', 'error');
    });
}

function clearPrenatalForm() {
    document.getElementById('bloodPressure').value = '';
    document.getElementById('weight').value = '';
    document.getElementById('height').value = '';
    document.getElementById('fundalHeight').value = '';
    document.getElementById('fetalHeartTone').value = '';
    document.getElementById('currentGestation').value = '';
    document.getElementById('pregnancyWeeks').value = '';
    document.getElementById('dueDate').value = '';
    document.getElementById('nausea').checked = false;
    document.getElementById('backPain').checked = false;
    document.getElementById('fatigue').checked = false;
    document.getElementById('swelling').checked = false;
    document.getElementById('headaches').checked = false;
    document.getElementById('smokingYes').checked = false;
    document.getElementById('alcoholYes').checked = false;
    document.getElementById('caffeineYes').checked = false;
    document.getElementById('exerciseYes').checked = false;
    document.getElementById('highBloodPressure').checked = false;
    document.getElementById('diabetes').checked = false;
    document.getElementById('thyroidDisorder').checked = false;
    document.getElementById('heartDisease').checked = false;
    document.getElementById('kidneyIssues').checked = false;
    document.getElementById('asthma').checked = false;
    document.getElementById('arthritis').checked = false;
    document.getElementById('cancer').checked = false;
    document.getElementById('cancerType').value = '';
    document.getElementById('otherConditions').checked = false;
    document.getElementById('otherConditionsDetails').value = '';
}

// ==================== FAMILY PLANNING FUNCTIONS ====================

function validateFamilyPlanForm() {
    const numChildren = document.getElementById('numChildren').value;
    const bloodPressure = document.getElementById('bloodPressure').value;
    const weight = document.getElementById('weight').value;
    const contraceptiveUse = document.querySelector('input[name="contraceptiveUse"]:checked');

    if (!numChildren || !bloodPressure || !weight || !contraceptiveUse) {
        Swal.fire('Warning', 'Please fill out all required fields', 'warning');
        return false;
    }
    return true;
}

function submitFamilyPlan() {
    if (!validateFamilyPlanForm()) return;

    const patientId = document.getElementById('patientIdFamilyPlan').textContent;
    const numChildren = document.getElementById('numChildren').value;
    const bloodPressure = document.getElementById('bloodPressure').value;
    const weight = document.getElementById('weight').value;
    const gravida = document.getElementById('gravida').value;
    const para = document.getElementById('para').value;
    const lmp = document.getElementById('lmp').value;
    const menstrualCycle = document.querySelector('input[name="menstrualCycle"]:checked')?.value || '';
    const hypertension = document.getElementById('hypertension').checked ? 'Yes' : 'No';
    const diabetes = document.getElementById('diabetes').checked ? 'Yes' : 'No';
    const heartDisease = document.getElementById('heartDisease').checked ? 'Yes' : 'No';
    const allergies = document.getElementById('allergies').value;
    const otherMedical = document.getElementById('otherMedical').value;
    const contraceptiveUse = document.querySelector('input[name="contraceptiveUse"]:checked')?.value || '';
    const contraceptiveMethod = document.getElementById('contraceptiveMethod').value;
    const firstTimeUser = document.getElementById('firstTimeUser').checked ? 'Yes' : 'No';
    const switchingMethods = document.getElementById('switchingMethods').checked ? 'Yes' : 'No';
    const counseling = document.getElementById('counseling').checked ? 'Yes' : 'No';
    const naturalFamilyPlanning = document.getElementById('naturalFamilyPlanning').checked ? 'Yes' : 'No';
    const barrierMethods = document.getElementById('barrierMethods').checked ? 'Yes' : 'No';
    const hormonalMethods = document.getElementById('hormonalMethods').checked ? 'Yes' : 'No';
    const iud = document.getElementById('IUD').checked ? 'Yes' : 'No';
    const permanentMethods = document.getElementById('permanentMethods').checked ? 'Yes' : 'No';
    const othersMethod = document.getElementById('othersMethod').value;
    const properUse = document.getElementById('properUse').checked ? 'Yes' : 'No';
    const sideEffects = document.getElementById('sideEffects').checked ? 'Yes' : 'No';
    const importanceFollowUp = document.getElementById('importanceFollowUp').checked ? 'Yes' : 'No';

    const formId = `FP-${patientId}-${Date.now()}`;

    const formData = {
        patientId,
        numChildren,
        bloodPressure,
        weight,
        gravida,
        para,
        lmp,
        menstrualCycle,
        hypertension,
        diabetes,
        heartDisease,
        allergies,
        otherMedical,
        contraceptiveUse,
        contraceptiveMethod,
        firstTimeUser,
        switchingMethods,
        counseling,
        naturalFamilyPlanning,
        barrierMethods,
        hormonalMethods,
        iud,
        permanentMethods,
        othersMethod,
        properUse,
        sideEffects,
        importanceFollowUp,
        formId
    };

    fetch('midwife.php?action=submit_family_plan', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify(formData)
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            Swal.fire('Success', `Family plan form submitted successfully! Form ID: ${data.formId}`, 'success');
            clearFamilyPlanForm();
            fetchPatientRecords(); // Refresh the patient records table
        } else {
            Swal.fire('Error', 'Failed to submit family plan form', 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        Swal.fire('Error', 'Failed to submit family plan form', 'error');
    });
}

function clearFamilyPlanForm() {
    document.getElementById('numChildren').value = '';
    document.getElementById('bloodPressure').value = '';
    document.getElementById('weight').value = '';
    document.getElementById('gravida').value = '';
    document.getElementById('para').value = '';
    document.getElementById('lmp').value = '';
    document.querySelectorAll('input[name="menstrualCycle"]').forEach(input => input.checked = false);
    document.getElementById('hypertension').checked = false;
    document.getElementById('diabetes').checked = false;
    document.getElementById('heartDisease').checked = false;
    document.getElementById('allergies').value = '';
    document.getElementById('otherMedical').value = '';
    document.querySelectorAll('input[name="contraceptiveUse"]').forEach(input => input.checked = false);
    document.getElementById('contraceptiveMethod').value = '';
    document.getElementById('firstTimeUser').checked = false;
    document.getElementById('switchingMethods').checked = false;
    document.getElementById('counseling').checked = false;
    document.getElementById('naturalFamilyPlanning').checked = false;
    document.getElementById('barrierMethods').checked = false;
    document.getElementById('hormonalMethods').checked = false;
    document.getElementById('IUD').checked = false;
    document.getElementById('permanentMethods').checked = false;
    document.getElementById('othersMethod').value = '';
    document.getElementById('properUse').checked = false;
    document.getElementById('sideEffects').checked = false;
    document.getElementById('importanceFollowUp').checked = false;
}

// ==================== INITIALIZATION ====================

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
});
</script>
</body>
</html>