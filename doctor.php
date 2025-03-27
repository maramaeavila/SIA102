<?php
session_start();
include 'config.php';

// Verify user is logged in and has the correct role
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

if ($_SESSION['role'] !== 'doctor') {
    header("Location: doctor.php");
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
                
                $stmt = $conn->prepare("SELECT * FROM appointments WHERE appointment_date = ? AND healthcare_provider = 'Doctor'");
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
                    WHERE appointment_date = ? AND healthcare_provider = 'Doctor'");
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
                
            case 'submit_general_checkup':
                $data = json_decode(file_get_contents('php://input'), true);
                
                $stmt = $conn->prepare("INSERT INTO general_checkups (
                    patient_id, height, weight, blood_pressure, temperature, 
                    pulse_rate, respiratory_rate, allergies, medications, 
                    past_medical_history, family_history, vaccinated, 
                    vaccine_type, booster_dose, booster_date, bmi, 
                    bmi_status, common_diseases, consultation, remarks, form_id
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                
                $stmt->execute([
                    $data['patientId'],
                    $data['height'],
                    $data['weight'],
                    $data['bloodPressure'],
                    $data['temperature'],
                    $data['pulseRate'],
                    $data['respiratoryRate'],
                    $data['allergies'],
                    $data['medications'],
                    $data['pastMedicalHistory'],
                    $data['familyHistory'],
                    $data['vaccinated'],
                    $data['vaccineType'],
                    $data['boosterDose'],
                    $data['boosterDate'],
                    $data['bmi'],
                    $data['bmiStatus'],
                    $data['commonDiseases'],
                    $data['consultation'],
                    $data['remarks'],
                    $data['formId']
                ]);
                
                echo json_encode(['success' => true, 'formId' => $data['formId']]);
                break;
                
            case 'get_checkups':
                $page = $_GET['page'] ?? 1;
                $search = $_GET['search'] ?? '';
                $limit = 10;
                $offset = ($page - 1) * $limit;
                
                if ($search) {
                    $stmt = $conn->prepare("SELECT * FROM general_checkups 
                                          WHERE patient_id LIKE ? OR form_id LIKE ?
                                          ORDER BY id DESC LIMIT ? OFFSET ?");
                    $searchTerm = "%$search%";
                    $stmt->execute([$searchTerm, $searchTerm, $limit, $offset]);
                    $countStmt = $conn->prepare("SELECT COUNT(*) FROM general_checkups 
                                               WHERE patient_id LIKE ? OR form_id LIKE ?");
                    $countStmt->execute([$searchTerm, $searchTerm]);
                } else {
                    $stmt = $conn->prepare("SELECT * FROM general_checkups 
                                          ORDER BY id DESC LIMIT ? OFFSET ?");
                    $stmt->execute([$limit, $offset]);
                    $countStmt = $conn->prepare("SELECT COUNT(*) FROM general_checkups");
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
                
            case 'get_checkup':
                $formId = $_GET['formId'];
                $stmt = $conn->prepare("SELECT * FROM general_checkups WHERE form_id = ?");
                $stmt->execute([$formId]);
                $record = $stmt->fetch(PDO::FETCH_ASSOC);
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
            <a class="nav-link d-flex align-items-center" href="#" onclick="showSection('generalCheckupRecords')">
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

        <h1 class="mt-5">Consultation</h1>
        <div class="row">
            <div class="col">
                <label for="height">Height (cm):</label>
                <input type="number" id="height" placeholder="Enter height" required />
                <br />

                <label for="weight">Weight (kg):</label>
                <input type="number" id="weight" placeholder="Enter weight" required />
                <br />

                <label for="bloodPressure">Blood Pressure:</label>
                <input type="text" id="bloodPressure" placeholder="Enter blood pressure" required />
                <span id="bloodPressureStatus"></span>
                <br />

                <label for="temperature" class="mt-3">Temperature (°C):</label>
                <input type="text" id="temperature" placeholder="Enter temperature" required />
                <span id="temperatureStatus"></span>
                <br />

                <label for="pulseRate" class="mt-3">Pulse Rate (bpm):</label>
                <input type="text" id="pulseRate" placeholder="Enter pulse rate" required />
                <span id="pulseRateStatus"></span>
                <br />

                <label for="respiratoryRate" class="mt-3">Respiratory Rate (bpm):</label>
                <input type="text" id="respiratoryRate" placeholder="Enter respiratory rate" required />
                <span id="respiratoryRateStatus"></span>
                <br />

                <label for="allergies" class="mt-3">Allergies (If any):</label>
                <div>
                    <input type="checkbox" id="noAllergies" onclick="toggleCheckboxAllergies('noAllergies')" />
                    <label for="noAllergies">None</label>
                    <br />
                    <input type="checkbox" id="hasAllergies" onclick="toggleCheckboxAllergies('hasAllergies')" />
                    <label for="hasAllergies">Yes, please specify:</label>
                    <input type="text" id="allergiesDetails" placeholder="Specify allergies" />
                </div>
                <br />

                <label for="currentMedications">Current Medications:</label>
                <div>
                    <input type="checkbox" id="noMedications" onclick="toggleCheckbox('noMedications')" />
                    <label for="noMedications">None</label>
                    <br />
                    <input type="checkbox" id="hasMedications" onclick="toggleCheckbox('hasMedications')" />
                    <label for="hasMedications">Yes, please specify:</label>
                    <input type="text" id="medicationsDetails" placeholder="Specify medications" />
                </div>
                <br />

                <label for="pastMedicalHistory">Past Medical History (Check all that apply):</label>
                <div>
                    <input type="checkbox" id="n/a" />
                    <label for="hypertension">N/A</label>
                    <br />
                    <input type="checkbox" id="hypertension" />
                    <label for="hypertension">Hypertension</label>
                    <br />
                    <input type="checkbox" id="diabetes" />
                    <label for="diabetes">Diabetes</label>
                    <br />
                    <input type="checkbox" id="heartDisease" />
                    <label for="heartDisease">Heart Disease</label>
                    <br />
                    <input type="checkbox" id="asthma" />
                    <label for="asthma">Asthma</label>
                    <br />
                    <input type="checkbox" id="tuberculosis" />
                    <label for="tuberculosis">Tuberculosis</label>
                    <br />
                    <input type="checkbox" id="otherConditions" />
                    <label for="otherConditions">Others (Please specify):</label>
                    <input type="text" id="otherConditionsDetails" placeholder="Specify other conditions" />
                </div>
                <br />
            </div>

            <div class="col">
                <label for="familyHistory">Family History of Illnesses:</label>
                <div>
                    <input type="checkbox" id="n/a" />
                    <label for="hypertension">N/A</label>
                    <br />
                    <input type="checkbox" id="familyHypertension" />
                    <label for="familyHypertension">Hypertension</label>
                    <br />
                    <input type="checkbox" id="familyDiabetes" />
                    <label for="familyDiabetes">Diabetes</label>
                    <br />
                    <input type="checkbox" id="familyHeartDisease" />
                    <label for="familyHeartDisease">Heart Disease</label>
                    <br />
                    <input type="checkbox" id="familyOther" />
                    <label for="familyOther">Others (Please specify):</label>
                    <input type="text" id="familyOtherDetails" placeholder="Specify family conditions" />
                </div>
                <br />

                <label for="covidVaccinated">Are You Covid-19 Vaccinated?</label>
                <div>
                    <input type="checkbox" id="vaccinatedYes" onclick="toggleVaccinationCheckbox('vaccinatedYes')" />
                    <label for="vaccinatedYes">Yes</label>
                    <input type="checkbox" id="vaccinatedNo" onclick="toggleVaccinationCheckbox('vaccinatedNo')" />
                    <label for="vaccinatedNo">No</label>
                </div>
                <br />

                <label for="vaccineType">Type of Vaccine:</label>
                <div>
                    <input type="checkbox" id="pfizer" onclick="toggleVaccineCheckbox('pfizer')" />
                    <label for="pfizer">Pfizer</label>
                    <input type="checkbox" id="moderna" onclick="toggleVaccineCheckbox('moderna')" />
                    <label for="moderna">Moderna</label>
                    <input type="checkbox" id="astrazeneca" onclick="toggleVaccineCheckbox('astrazeneca')" />
                    <label for="astrazeneca">AstraZeneca</label>
                    <input type="checkbox" id="sinovac" onclick="toggleVaccineCheckbox('sinovac')" />
                    <label for="sinovac">Sinovac</label>
                </div>
                <br />

                <label for="boosterDose">Booster Dose Received?</label>
                <div>
                    <input type="checkbox" id="boosterYes" onclick="toggleBoosterCheckbox('boosterYes')" />
                    <label for="boosterYes">Yes</label>
                    <input type="checkbox" id="boosterNo" onclick="toggleBoosterCheckbox('boosterNo')" />
                    <label for="boosterNo">No</label>
                </div>
                <br />

                <label for="boosterDate">Date of Last Booster Received:</label>
                <input type="date" id="boosterDate" />
                <br />

                <p><strong>BMI:</strong> <span id="bmi"></span></p>
                <p><strong>BMI Status:</strong> <span id="bmiStatus"></span></p>

                <label>Common Diseases</label>
                <div>
                  <label><input type="checkbox" id="hypertension" /> Hypertension</label><br />
                  <label><input type="checkbox" id="animalBites" /> Animal Bites</label><br />
                  <label><input type="checkbox" id="dengue" /> Dengue</label><br />
                  <label><input type="checkbox" id="skinDiseases" /> Skin Diseases</label><br />
                  <label><input type="checkbox" id="pneumonia" /> Pneumonia</label><br />
                  <label><input type="checkbox" id="tb" /> Tuberculosis</label><br />
                  <label><input type="checkbox" id="fever" /> Fever of Unknown Origin</label><br />
                  <label><input type="checkbox" id="coughAndCold" /> Cough and Cold</label><br />
                </div>
                <br />
                
                  <label for="consultation">Consultation:</label>
                  <div>
                  <textarea id="consultation" rows="3" placeholder="Enter consultation details" class="form-control"></textarea>
                </div>
              
                
                  <label for="remarks">Remarks:</label>
                  <div>
                  <textarea id="remarks" rows="3" placeholder="Enter remarks" class="form-control"></textarea>
                </div>
                <br />
              </div>
            </div>
            <div class="mb-5 flexend">
                <button class="btn btn-primary" onclick="submitGeneralCheckup()">Submit General Checkup</button>
            </div>  
    </div>

    <!-- Patient Records Section -->
    <div id="generalCheckupRecords" class="content-section" style="display: none;">
        <h1 class="mt-5">General Checkup Records</h1>
        
        <div class="mb-3">
            <input type="text" id="searchCheckup" class="form-control" placeholder="Search Checkup Records" onkeyup="searchCheckups()" style="width: 20%;">
        </div>

        <div class="table-responsive">
            <table class="table table-striped">
                <thead>
                    <tr>
                        <th>Form ID</th>
                        <th>Height (cm)</th>
                        <th>Weight (kg)</th>
                        <th>Blood Pressure</th>
                        <th>Temperature (°C)</th>
                        <th>Vaccinated</th>
                        <th>Consultation</th>
                    </tr>
                </thead>
                <tbody id="generalCheckupListData">
                </tbody>
            </table>
        </div>
        
        <div class="pagination">
            <button id="prevPage" class="btn btn-secondary" onclick="prevPage()">Previous</button>
            <span id="pageInfo" class="mx-3">Page 1</span>
            <button id="nextPage" class="btn btn-secondary" onclick="nextPage()">Next</button>
        </div>
    </div>

    <!-- Checkup Modal -->
    <div id="checkupModal" class="modal" style="display: none;">
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

        <div class="table-responsive mt-3">
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
let allCheckups = [];
let filteredCheckups = [];
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
        case 'generalCheckupRecords':
            fetchCheckupData();
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
    document.getElementById('checkupModal').style.display = 'none';
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

// ==================== CHECKUP FORM FUNCTIONS ====================

function toggleCheckboxAllergies(checkedId) {
    const noAllergies = document.getElementById('noAllergies');
    const hasAllergies = document.getElementById('hasAllergies');
    const allergiesDetails = document.getElementById('allergiesDetails');

    if (checkedId === 'noAllergies' && noAllergies.checked) {
        hasAllergies.checked = false;
        allergiesDetails.disabled = true;
        allergiesDetails.value = '';
    } else if (checkedId === 'hasAllergies' && hasAllergies.checked) {
        noAllergies.checked = false;
        allergiesDetails.disabled = false;
    } else {
        allergiesDetails.disabled = true;
        allergiesDetails.value = '';
    }
}

function toggleCheckbox(checkedId) {
    const noMedications = document.getElementById('noMedications');
    const hasMedications = document.getElementById('hasMedications');
    const medicationsDetails = document.getElementById('medicationsDetails');

    if (checkedId === 'noMedications' && noMedications.checked) {
        hasMedications.checked = false;
        medicationsDetails.disabled = true;
        medicationsDetails.value = '';
    } else if (checkedId === 'hasMedications' && hasMedications.checked) {
        noMedications.checked = false;
        medicationsDetails.disabled = false;
    } else {
        medicationsDetails.disabled = true;
        medicationsDetails.value = '';
    }
}

function toggleVaccinationCheckbox(checkedId) {
    const vaccinatedYes = document.getElementById('vaccinatedYes');
    const vaccinatedNo = document.getElementById('vaccinatedNo');

    if (checkedId === 'vaccinatedYes' && vaccinatedYes.checked) {
        vaccinatedNo.checked = false;
    } else if (checkedId === 'vaccinatedNo' && vaccinatedNo.checked) {
        vaccinatedYes.checked = false;
    }
}

function toggleBoosterCheckbox(checkedId) {
    const boosterYes = document.getElementById('boosterYes');
    const boosterNo = document.getElementById('boosterNo');

    if (checkedId === 'boosterYes' && boosterYes.checked) {
        boosterNo.checked = false;
    } else if (checkedId === 'boosterNo' && boosterNo.checked) {
        boosterYes.checked = false;
    }
}

function toggleVaccineCheckbox(checkedId) {
    const vaccineCheckboxes = ['pfizer', 'moderna', 'astrazeneca', 'sinovac'];
    
    if (document.getElementById(checkedId).checked) {
        vaccineCheckboxes.forEach(id => {
            if (id !== checkedId) {
                document.getElementById(id).checked = false;
            }
        });
    }
}

function updateStatus() {
    const setStatus = (element, status, color) => {
        element.textContent = status;
        element.style.color = color;
    };

    const bloodPressure = document.getElementById('bloodPressure').value;
    const bloodPressureStatus = document.getElementById('bloodPressureStatus');
    if (bloodPressure) {
        const [systolic, diastolic] = bloodPressure.split('/').map(Number);
        if (systolic < 90 || diastolic < 60) {
            setStatus(bloodPressureStatus, 'Low (hypotension)', 'green');
        } else if (systolic >= 130 || diastolic >= 80) {
            setStatus(bloodPressureStatus, 'High (hypertension)', 'red');
        } else {
            setStatus(bloodPressureStatus, 'Normal', 'blue');
        }
    }

    const temperature = document.getElementById('temperature').value;
    const temperatureStatus = document.getElementById('temperatureStatus');
    if (temperature) {
        const tempValue = parseFloat(temperature);
        if (tempValue < 36.5) {
            setStatus(temperatureStatus, 'Low (hypothermia)', 'green');
        } else if (tempValue > 38.2) {
            setStatus(temperatureStatus, 'High (fever)', 'red');
        } else {
            setStatus(temperatureStatus, 'Normal', 'blue');
        }
    }

    const pulseRate = document.getElementById('pulseRate').value;
    const pulseRateStatus = document.getElementById('pulseRateStatus');
    if (pulseRate) {
        const pulse = parseInt(pulseRate, 10);
        if (pulse < 60) {
            setStatus(pulseRateStatus, 'Low (slow heart rate)', 'green');
        } else if (pulse > 100) {
            setStatus(pulseRateStatus, 'High (fast heart rate)', 'red');
        } else {
            setStatus(pulseRateStatus, 'Normal', 'blue');
        }
    }

    const respiratoryRate = document.getElementById('respiratoryRate').value;
    const respiratoryRateStatus = document.getElementById('respiratoryRateStatus');
    if (respiratoryRate) {
        const rate = parseInt(respiratoryRate, 10);
        if (rate < 12) {
            setStatus(respiratoryRateStatus, 'Low (slow breathing)', 'green');
        } else if (rate > 20) {
            setStatus(respiratoryRateStatus, 'High (fast breathing)', 'red');
        } else {
            setStatus(respiratoryRateStatus, 'Normal', 'blue');
        }
    }
}

function computeBMI() {
    const height = document.getElementById('height').value;
    const weight = document.getElementById('weight').value;

    if (height && weight) {
        const bmi = (weight / (height * height)) * 10000;
        document.getElementById('bmi').textContent = bmi.toFixed(2);

        let status = '';
        if (bmi < 18.5) {
            status = 'Underweight';
        } else if (bmi >= 18.5 && bmi < 24.9) {
            status = 'Normal (Healthy Weight)';
        } else if (bmi >= 30) {
            status = 'Obese';
        } else {
            status = 'Overweight';
        }

        document.getElementById('bmiStatus').textContent = status;
    } else {
        document.getElementById('bmi').textContent = 'N/A';
        document.getElementById('bmiStatus').textContent = 'N/A';
    }
}

function getCheckedConditions(category) {
    const conditions = [];
    const checkboxes = document.querySelectorAll(`#${category} input[type="checkbox"]:checked`);
    
    checkboxes.forEach(checkbox => {
        if (checkbox.id !== 'n/a') {
            conditions.push(checkbox.nextElementSibling.textContent.trim());
        }
    });
    
    return conditions.length > 0 ? conditions : ['None'];
}

function getVaccineType() {
    const vaccineTypes = [];
    if (document.getElementById('pfizer').checked) vaccineTypes.push('Pfizer');
    if (document.getElementById('moderna').checked) vaccineTypes.push('Moderna');
    if (document.getElementById('astrazeneca').checked) vaccineTypes.push('AstraZeneca');
    if (document.getElementById('sinovac').checked) vaccineTypes.push('Sinovac');
    return vaccineTypes.length > 0 ? vaccineTypes : ['None'];
}

function getCommonDiseases() {
    const diseases = [];
    const diseaseCheckboxes = [
        'hypertension', 'animalBites', 'dengue', 'skinDiseases', 
        'pneumonia', 'tb', 'fever', 'coughAndCold'
    ];
    
    diseaseCheckboxes.forEach(id => {
        if (document.getElementById(id).checked) {
            diseases.push(document.querySelector(`label[for="${id}"]`).textContent.trim());
        }
    });
    
    return diseases.length > 0 ? diseases : ['None'];
}

function validateForm() {
    const height = document.getElementById('height').value;
    const weight = document.getElementById('weight').value;
    const bloodPressure = document.getElementById('bloodPressure').value;
    const patientId = document.getElementById('patientIdInput').value.trim();

    if (!patientId) {
        Swal.fire('Warning', 'Please enter a valid Resident ID', 'warning');
        return false;
    }

    if (!height || !weight || !bloodPressure) {
        Swal.fire('Warning', 'Please fill out all required fields', 'warning');
        return false;
    }
    return true;
}

function submitGeneralCheckup() {
    if (!validateForm()) return;

    const patientId = document.getElementById('patientId').textContent;
    const height = document.getElementById('height').value;
    const weight = document.getElementById('weight').value;
    const bloodPressure = document.getElementById('bloodPressure').value;
    const temperature = document.getElementById('temperature').value;
    const pulseRate = document.getElementById('pulseRate').value;
    const respiratoryRate = document.getElementById('respiratoryRate').value;
    
    const allergies = document.getElementById('hasAllergies').checked 
        ? document.getElementById('allergiesDetails').value || 'None'
        : 'None';
    
    const medications = document.getElementById('hasMedications').checked 
        ? document.getElementById('medicationsDetails').value || 'None'
        : 'None';
    
    const pastMedicalHistory = getCheckedConditions('pastMedicalHistory');
    const familyHistory = getCheckedConditions('familyHistory');
    
    const vaccinated = document.getElementById('vaccinatedYes').checked ? 'Yes' : 'No';
    const vaccineType = getVaccineType();
    const boosterDose = document.getElementById('boosterYes').checked ? 'Yes' : 'No';
    const boosterDate = document.getElementById('boosterDate').value;
    
    computeBMI();
    const bmi = document.getElementById('bmi').textContent;
    const bmiStatus = document.getElementById('bmiStatus').textContent;
    
    const commonDiseases = getCommonDiseases();
    const consultation = document.getElementById('consultation').value || 'None';
    const remarks = document.getElementById('remarks').value || 'None';
    
    const formId = `GC-${patientId}-${Date.now()}`;

    const formData = {
        patientId,
        height,
        weight,
        bloodPressure,
        temperature,
        pulseRate,
        respiratoryRate,
        allergies,
        medications,
        pastMedicalHistory,
        familyHistory,
        vaccinated,
        vaccineType,
        boosterDose,
        boosterDate,
        bmi,
        bmiStatus,
        commonDiseases,
        consultation,
        remarks,
        formId
    };

    fetch('dental.php?action=submit_general_checkup', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify(formData)
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            Swal.fire('Success', `General checkup submitted successfully! Form ID: ${data.formId}`, 'success');
            clearForm();
        } else {
            Swal.fire('Error', 'Failed to submit general checkup', 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        Swal.fire('Error', 'Failed to submit general checkup', 'error');
    });
}

function clearForm() {
    document.getElementById('height').value = '';
    document.getElementById('weight').value = '';
    document.getElementById('bloodPressure').value = '';
    document.getElementById('temperature').value = '';
    document.getElementById('pulseRate').value = '';
    document.getElementById('respiratoryRate').value = '';
    document.getElementById('allergiesDetails').value = '';
    document.getElementById('medicationsDetails').value = '';
    document.getElementById('otherConditionsDetails').value = '';
    document.getElementById('familyOtherDetails').value = '';
    document.getElementById('boosterDate').value = '';
    document.getElementById('consultation').value = '';
    document.getElementById('remarks').value = '';
    
    document.getElementById('bmi').textContent = 'N/A';
    document.getElementById('bmiStatus').textContent = 'N/A';
    document.getElementById('bloodPressureStatus').textContent = '';
    document.getElementById('temperatureStatus').textContent = '';
    document.getElementById('pulseRateStatus').textContent = '';
    document.getElementById('respiratoryRateStatus').textContent = '';
    
    // Uncheck all checkboxes
    document.querySelectorAll('input[type="checkbox"]').forEach(checkbox => {
        checkbox.checked = false;
    });
    
    // Reset radio buttons
    document.getElementById('noAllergies').checked = true;
    document.getElementById('noMedications').checked = true;
    document.getElementById('vaccinatedNo').checked = true;
    document.getElementById('boosterNo').checked = true;
    
    // Reset vaccine type checkboxes
    document.getElementById('pfizer').checked = false;
    document.getElementById('moderna').checked = false;
    document.getElementById('astrazeneca').checked = false;
    document.getElementById('sinovac').checked = false;
    
    // Enable all disabled inputs
    document.getElementById('allergiesDetails').disabled = true;
    document.getElementById('medicationsDetails').disabled = true;
}

// ==================== CHECKUP RECORDS FUNCTIONS ====================

function fetchCheckupData() {
    fetch(`dental.php?action=get_checkups&page=${currentPage}`)
        .then(response => response.json())
        .then(data => {
            allCheckups = data.records;
            filteredCheckups = [...allCheckups];
            displayCheckups(data.total, data.pages);
        })
        .catch(error => {
            console.error('Error:', error);
            Swal.fire('Error', 'Failed to load checkup records', 'error');
        });
}

function displayCheckups(total, totalPages) {
    const tableBody = document.getElementById('generalCheckupListData');
    tableBody.innerHTML = '';
    
    if (filteredCheckups.length > 0) {
        filteredCheckups.forEach(record => {
            const row = document.createElement('tr');
            row.innerHTML = `
                <td>${record.form_id}</td>
                <td>${record.height}</td>
                <td>${record.weight}</td>
                <td>${record.blood_pressure}</td>
                <td>${record.temperature}</td>
                <td>${record.vaccinated}</td>
                <td>${record.consultation}</td>
            `;
            row.addEventListener('click', () => openCheckupModal(record.form_id));
            tableBody.appendChild(row);
        });
    } else {
        const row = document.createElement('tr');
        row.innerHTML = '<td colspan="7">No checkup records found</td>';
        tableBody.appendChild(row);
    }
    
    document.getElementById('pageInfo').textContent = `Page ${currentPage} of ${totalPages}`;
    document.getElementById('prevPage').disabled = currentPage === 1;
    document.getElementById('nextPage').disabled = currentPage >= totalPages;
}

function openCheckupModal(formId) {
    fetch(`dental.php?action=get_checkup&formId=${formId}`)
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
                        <p><strong>Height:</strong> ${record.height} cm</p>
                        <p><strong>Weight:</strong> ${record.weight} kg</p>
                        <p><strong>Blood Pressure:</strong> ${record.blood_pressure}</p>
                        <p><strong>Temperature:</strong> ${record.temperature} °C</p>
                        <p><strong>Pulse Rate:</strong> ${record.pulse_rate} bpm</p>
                        <p><strong>Respiratory Rate:</strong> ${record.respiratory_rate} breaths/min</p>
                        <p><strong>Allergies:</strong> ${record.allergies}</p>
                        <p><strong>Medications:</strong> ${record.medications}</p>
                    </div>
                    <div style="flex: 1; min-width: 250px;">
                        <p><strong>Past Medical History:</strong> ${record.past_medical_history}</p>
                        <p><strong>Family History:</strong> ${record.family_history}</p>
                        <p><strong>Vaccinated:</strong> ${record.vaccinated}</p>
                        <p><strong>Vaccine Type:</strong> ${record.vaccine_type}</p>
                        <p><strong>Booster Dose:</strong> ${record.booster_dose}</p>
                        <p><strong>Booster Date:</strong> ${record.booster_date}</p>
                        <p><strong>BMI:</strong> ${record.bmi} (${record.bmi_status})</p>
                        <p><strong>Common Diseases:</strong> ${record.common_diseases}</p>
                        <p><strong>Consultation:</strong> ${record.consultation}</p>
                        <p><strong>Remarks:</strong> ${record.remarks}</p>
                    </div>
                </div>
            `;
            
            document.getElementById('checkupModal').style.display = 'flex';
        })
        .catch(error => {
            console.error('Error:', error);
            Swal.fire('Error', 'Failed to load checkup details', 'error');
        });
}

function searchCheckups() {
    const searchTerm = document.getElementById('searchCheckup').value.toLowerCase();
    
    if (searchTerm.trim() === '') {
        filteredCheckups = [...allCheckups];
    } else {
        filteredCheckups = allCheckups.filter(record => {
            return (
                record.form_id.toLowerCase().includes(searchTerm) ||
                record.patient_id.toLowerCase().includes(searchTerm) ||
                record.consultation.toLowerCase().includes(searchTerm)
            );
        });
    }
    
    currentPage = 1;
    fetchCheckupData();
}

function prevPage() {
    if (currentPage > 1) {
        currentPage--;
        fetchCheckupData();
    }
}

function nextPage() {
    currentPage++;
    fetchCheckupData();
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
    
    // Initialize form event listeners
    document.getElementById('height').addEventListener('input', computeBMI);
    document.getElementById('weight').addEventListener('input', computeBMI);
    document.getElementById('height').addEventListener('input', updateStatus);
    document.getElementById('weight').addEventListener('input', updateStatus);
    document.getElementById('bloodPressure').addEventListener('input', updateStatus);
    document.getElementById('temperature').addEventListener('input', updateStatus);
    document.getElementById('pulseRate').addEventListener('input', updateStatus);
    document.getElementById('respiratoryRate').addEventListener('input', updateStatus);
    
    // Close modal when clicking outside
    document.getElementById('checkupModal').addEventListener('click', function(e) {
        if (e.target === this) {
            closeModal();
        }
    });
});
</script>
</body>
</html>