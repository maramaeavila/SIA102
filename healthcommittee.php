<?php
session_start();
include 'config.php';

// Verify user is logged in and has the correct role
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

if ($_SESSION['role'] !== 'healthcommittee') {
    header("Location: healcommittee.php");
    exit();
}

// Handle API requests
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    
    try {
        switch ($_GET['action']) {
            // Dashboard functions
            case 'get_dashboard_data':
                $date = $_GET['date'] ?? date('Y-m-d');
                $formattedDate = date('m/d/Y', strtotime($date));
                
                // Total appointments
                $stmt = $conn->prepare("SELECT COUNT(*) as total FROM appointments WHERE appointment_date = ?");
                $stmt->execute([$formattedDate]);
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                
                // Pending appointments
                $stmt = $conn->prepare("SELECT COUNT(*) as pending FROM appointments WHERE appointment_date = ? AND status = 'PENDING'");
                $stmt->execute([$formattedDate]);
                $result += $stmt->fetch(PDO::FETCH_ASSOC);
                
                // Completed appointments
                $stmt = $conn->prepare("SELECT COUNT(*) as completed FROM appointments WHERE appointment_date = ? AND status = 'COMPLETED'");
                $stmt->execute([$formattedDate]);
                $result += $stmt->fetch(PDO::FETCH_ASSOC);
                
                // Canceled appointments
                $stmt = $conn->prepare("SELECT COUNT(*) as canceled FROM appointments WHERE appointment_date = ? AND status = 'CANCELED'");
                $stmt->execute([$formattedDate]);
                $result += $stmt->fetch(PDO::FETCH_ASSOC);
                
                echo json_encode($result);
                break;
                
            // Resident functions
            case 'get_residents':
                $page = $_GET['page'] ?? 1;
                $search = $_GET['search'] ?? '';
                $limit = 10;
                $offset = ($page - 1) * $limit;
                
                if ($search) {
                    $stmt = $conn->prepare("SELECT * FROM residents 
                                          WHERE first_name LIKE ? OR last_name LIKE ? OR id LIKE ?
                                          ORDER BY last_name LIMIT ? OFFSET ?");
                    $searchTerm = "%$search%";
                    $stmt->execute([$searchTerm, $searchTerm, $searchTerm, $limit, $offset]);
                    
                    $countStmt = $conn->prepare("SELECT COUNT(*) FROM residents 
                                               WHERE first_name LIKE ? OR last_name LIKE ? OR id LIKE ?");
                    $countStmt->execute([$searchTerm, $searchTerm, $searchTerm]);
                } else {
                    $stmt = $conn->prepare("SELECT * FROM residents ORDER BY last_name LIMIT ? OFFSET ?");
                    $stmt->execute([$limit, $offset]);
                    
                    $countStmt = $conn->prepare("SELECT COUNT(*) FROM residents");
                    $countStmt->execute();
                }
                
                $residents = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $total = $countStmt->fetchColumn();
                
                echo json_encode([
                    'residents' => $residents,
                    'total' => $total,
                    'pages' => ceil($total / $limit)
                ]);
                break;
                
            case 'get_resident':
                $residentId = $_GET['id'];
                $stmt = $conn->prepare("SELECT * FROM residents WHERE id = ?");
                $stmt->execute([$residentId]);
                $resident = $stmt->fetch(PDO::FETCH_ASSOC);
                echo json_encode($resident ?: ['error' => 'Resident not found']);
                break;
                
            // Appointment functions
            case 'get_appointments':
                $date = $_GET['date'] ?? date('Y-m-d');
                $formattedDate = date('m/d/Y', strtotime($date));
                
                $stmt = $conn->prepare("SELECT * FROM appointments WHERE appointment_date = ?");
                $stmt->execute([$formattedDate]);
                $appointments = $stmt->fetchAll(PDO::FETCH_ASSOC);
                echo json_encode($appointments);
                break;
                
            case 'update_appointment':
                $id = $_POST['id'];
                $status = $_POST['status'];
                
                $stmt = $conn->prepare("UPDATE appointments SET status = ? WHERE id = ?");
                $stmt->execute([$status, $id]);
                echo json_encode(['success' => true]);
                break;
                
            // Healthcare workers functions
            case 'get_healthcare_workers':
                $stmt = $conn->prepare("SELECT * FROM healthcare_workers WHERE department = 'HEALTH_DEPARTMENT'");
                $stmt->execute();
                $workers = $stmt->fetchAll(PDO::FETCH_ASSOC);
                echo json_encode($workers);
                break;
                
            case 'get_healthcare_worker':
                $workerId = $_GET['id'];
                $stmt = $conn->prepare("SELECT * FROM healthcare_workers WHERE id = ?");
                $stmt->execute([$workerId]);
                $worker = $stmt->fetch(PDO::FETCH_ASSOC);
                echo json_encode($worker ?: ['error' => 'Worker not found']);
                break;
                
            // Medicine functions
            case 'get_medicines':
                $stmt = $conn->prepare("SELECT * FROM medicines");
                $stmt->execute();
                $medicines = $stmt->fetchAll(PDO::FETCH_ASSOC);
                echo json_encode($medicines);
                break;
                
            case 'add_medicine':
                $category = $_POST['category'];
                $name = $_POST['name'];
                $quantity = $_POST['quantity'];
                $dateAdded = $_POST['date_added'];
                $expirationDate = $_POST['expiration_date'];
                
                $stmt = $conn->prepare("INSERT INTO medicines (category, name, quantity, date_added, expiration_date) 
                                      VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$category, $name, $quantity, $dateAdded, $expirationDate]);
                echo json_encode(['success' => true]);
                break;
                
            case 'release_medicine':
                $medicineId = $_POST['medicine_id'];
                $residentId = $_POST['resident_id'];
                $quantity = $_POST['quantity'];
                
                // Update medicine quantity
                $stmt = $conn->prepare("UPDATE medicines SET quantity = quantity - ? WHERE id = ?");
                $stmt->execute([$quantity, $medicineId]);
                
                // Record the release
                $stmt = $conn->prepare("INSERT INTO medicine_releases (medicine_id, resident_id, quantity, release_date) 
                                      VALUES (?, ?, ?, NOW())");
                $stmt->execute([$medicineId, $residentId, $quantity]);
                
                echo json_encode(['success' => true]);
                break;
                
            // Request functions
            case 'get_requests':
                $stmt = $conn->prepare("SELECT * FROM requests");
                $stmt->execute();
                $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
                echo json_encode($requests);
                break;
                
            case 'submit_request':
                $requesterName = $_POST['requester_name'];
                $requesterEmail = $_POST['requester_email'];
                $requestType = $_POST['request_type'];
                $description = $_POST['description'];
                $comments = $_POST['comments'] ?? '';
                
                // Handle file upload
                $filePath = '';
                if (isset($_FILES['file'])) {
                    $uploadDir = 'uploads/requests/';
                    $fileName = basename($_FILES['file']['name']);
                    $filePath = $uploadDir . uniqid() . '_' . $fileName;
                    
                    if (move_uploaded_file($_FILES['file']['tmp_name'], $filePath)) {
                        // File uploaded successfully
                    }
                }
                
                $stmt = $conn->prepare("INSERT INTO requests (requester_name, requester_email, request_type, description, comments, file_path, status) 
                                      VALUES (?, ?, ?, ?, ?, ?, 'PENDING')");
                $stmt->execute([$requesterName, $requesterEmail, $requestType, $description, $comments, $filePath]);
                echo json_encode(['success' => true]);
                break;
                
            // Time in/out functions
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
        /* All the CSS styles from healthcommittee.html */
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
        
        /* Add any additional styles from healthcommittee.html */
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
            <a class="nav-link d-flex align-items-center" href="#" onclick="showSection('residentSection')">
                <i class="fa-solid fa-house-medical mr-2"></i> 
                Resident List
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link d-flex align-items-center" href="#" onclick="showSection('appointmentSection')">
                <i class="fa-solid fa-phone mr-2"></i> 
                Appointment
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link d-flex align-items-center" href="#" onclick="showSection('healthcareSection')">
                <i class="fa-solid fa-user-nurse mr-2"></i> 
                Healthcare Workers
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link d-flex align-items-center" href="#" onclick="showSection('medicineSection')">
                <i class="fa-solid fa-pills mr-2"></i> 
                Medicine
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link d-flex align-items-center" href="#" onclick="showSection('requestSection')">
                <i class="fa-solid fa-envelope mr-2"></i> 
                Request
            </a>
        </li>
        <li class="nav-item">
            <a href="#" class="nav-link d-flex align-items-center" onclick="showSection('timeInOutSection')">
                <i class="fa-solid fa-clock mr-2"></i> 
                Time In/Time Out
            </a>
        </li>
    </ul>
</div>

<!-- Main Content Area -->
<div class="content">
    <!-- Dashboard Section -->
    <div id="dashboardSection" class="content-section">
        <h1 class="mt-5">Appointment Dashboard</h1>
        
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
    

    <!-- Resident Section -->
    <div id="residentSection" class="content-section" style="display: none;">
        <h1 class="mt-5">Resident List</h1>
        <div class="table-container">
        <input type="text" id="searchResident" placeholder="Search residents..." oninput="searchResidents()" />
            <table>
                <thead>
                    <tr>
                        <th>Resident ID</th>
                        <th>Name</th>
                        <th>Mobile Number</th>
                        <th>Email</th>
                    </tr>
                </thead>
                <tbody id="residentListData">
                    
                </tbody>
            </table>
        <div class="pagination">
            <button id="prevPage" onclick="prevPage()">Previous</button>
            <span id="pageInfo"></span>
            <button id="nextPage" onclick="nextPage()">Next</button>
        </div>
    </div>
    </div>

    <!-- Resident Modal -->
    <div id="residentModal" class="modal" style="display: none;">
        <div class="modal-content">
            <span class="close" onclick="closeModal()">&times;</span>
            <h2>Resident Details</h2>
            <div id="modalContent">
                
            </div>
        </div>
    </div>

    <!-- Appointment Section -->
    <div id="appointmentSection" class="content-section" style="display: none;">
        <h1 class="mt-5">Appointment Calendar</h1>
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
                <select id="yearSelect" class="form-select" style="width: auto; display: inline-block;">
                </select>
                <div class="calendar-body" id="calendarDays">
                </div>
            </div>

            <div class="schedule">
                <div class="schedule-header">Appointments</div>
                <ul class="appointment-list" id="appointmentList">
                    <li>Select a date to view appointments.</li>
                </ul>
            </div>
        </div>
    </div>

    <!-- Healthcare Workers Section -->
    <div id="healthcareSection" class="content-section" style="display: none;">
        <h1 class="mt-5">Health Department Employees</h1>
        <div class="table-container">
          <table>
            <thead>
              <tr>
                <th>Employee ID</th>
                <th>Name</th>
                <th>Role</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody id="departmentListData"></tbody>
          </table>
        </div>
      </div>
      
      <!-- Employee Modal -->
      <div id="employeeModal" class="modal" style="display: none;">
        <div class="modal-content">
          <span class="close" onclick="closeEmployeeModal()">&times;</span>
          <h2>Employee Details</h2>
          <div id="modalEmployeeContent"></div>
        </div>
      </div>

    <!-- Medicine Section -->
    <div id="medicineSection" class="content-section" style="display: none;">
        <h1 class="mt-5">Medicine Stocks</h1>
        <ul class="nav nav-tabs" id="medicineTab" role="tablist" style="margin: 8px;">
            <li class="nav-item">
                <button class="nav-link active" data-tab="medicineTabPane" onclick="showMedicineTab('medicineTabPane')">Medicine & Stocks</button>
            </li>
            <li class="nav-item">
                <button class="nav-link" data-tab="guidelinesTabPane" onclick="showMedicineTab('guidelinesTabPane')">Guidelines</button>
            </li>
        </ul>
    
        <div class="tab-content" id="medicineTabContent">
            <div class="tab-pane fade show active" id="medicineTabPane" role="tabpanel">
                <div id="medicineReleaseModal" class="modal" style="display: none;">
                    <div class="modal-content">
                      <span class="close" onclick="closeReleaseModal()">&times;</span>
                      <h2>Release Medicine</h2>
                      <form id="releaseForm">
                        <label for="residentId">Resident ID:</label>
                        <input type="text" id="residentId" required><br><br>
                  
                        <div id="batchDetails"></div> 
                  
                        <button type="button" class="btn btn-primary" onclick="handleMedicineRelease()">Release</button>
                      </form>
                    </div>
                  </div>
                  
                  <!-- Inventory List -->
                  <h1 class="mt-5 fonts">Medicine Stocks</h1>
                  <p>View the current stock of medicines here.</p>
                  <div class="table-container">
                    <table id="inventoryList">
                        <thead>
                        <tr>
                            <th>Category</th>
                            <th>Name</th>
                            <th>Quantity</th>
                            <th>Date Added</th>
                            <th>Expiration Date</th>
                            <th>Action</th>
                        </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div> 
            </div>    
          
            <!-- Guidelines Tab Pane -->
            <div class="tab-pane fade" id="guidelinesTabPane" role="tabpanel">
                <h1 class="fonts mt-5">Family Planning Services</h1>
                <div class="table-responsive">
                    <table class="table table-striped">
                        <thead>
                            <tr>
                                <th>Service/Product</th>
                                <th>Usage Instructions</th>
                                <th>Additional Information</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>Pills (Oral Contraceptives)</td>
                                <td>Take daily, one tablet per day.</td>
                                <td>Usually provided as a one-month supply.</td>
                            </tr>
                            <tr>
                                <td>Condoms</td>
                                <td>Use each time during intercourse; apply correctly before starting.</td>
                                <td>Free, available in quantities as needed.</td>
                            </tr>
                            <tr>
                                <td>Injectables (Depo-Provera)</td>
                                <td>One injection every 3 months.</td>
                                <td>Maximum dose: one injection every 3 months.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            
                <h1 class="fonts mt-5">Common Medications</h1>
                <div class="table-responsive">
                    <table class="table table-striped">
                        <thead>
                            <tr>
                                <th>Medication</th>
                                <th>Usage</th>
                                <th>Maximum Dose</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>Paracetamol</td>
                                <td>1 tablet (500 mg) every 4-6 hours.</td>
                                <td>Up to 8 tablets within 24 hours.</td>
                            </tr>
                            <tr>
                                <td>Amoxicillin</td>
                                <td>Antibiotic, usually taken 3 times a day (500 mg).</td>
                                <td>Typically a 7-10 day course.</td>
                            </tr>
                            <tr>
                                <td>Cough Syrup</td>
                                <td>1-2 teaspoons every 6 hours.</td>
                                <td>Duration depends on doctor's prescription.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            
                <h1 class="fonts mt-5">Vaccines for Children</h1>
                <div class="table-responsive">
                    <table class="table table-striped">
                        <thead>
                            <tr>
                                <th>Vaccine</th>
                                <th>Usage</th>
                                <th>Additional Information</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>BCG (Bacillus Calmette-Guerin) Vaccine</td>
                                <td>For TB, given at birth.</td>
                                <td>Single dose.</td>
                            </tr>
                            <tr>
                                <td>Hepatitis B Vaccine</td>
                                <td>For Hepatitis B, given within the first 24 hours of life.</td>
                                <td>First dose.</td>
                            </tr>
                            <tr>
                                <td>Pentavalent Vaccine (DPT-HepB-Hib)</td>
                                <td>For diphtheria, pertussis, tetanus, hepatitis B, and Hib.</td>
                                <td>3 doses (at 6, 10, and 14 weeks).</td>
                            </tr>
                            <tr>
                                <td>Oral Polio Vaccine (OPV)</td>
                                <td>For Polio, given at 6 weeks, 10 weeks, and 14 weeks.</td>
                                <td>3 doses.</td>
                            </tr>
                            <tr>
                                <td>Rotavirus Vaccine</td>
                                <td>For Diarrhea, given at 6 weeks, 10 weeks, and 14 weeks (if 3 doses).</td>
                                <td>2–3 doses.</td>
                            </tr>
                            <tr>
                                <td>Japanese Encephalitis (JE) Vaccine</td>
                                <td>For Japanese Encephalitis, given at 9 months and above.</td>
                                <td>Single dose.</td>
                            </tr>
                            <tr>
                                <td>Tigdas (Measles) Vaccine</td>
                                <td>For Measles, given at 9 months, with a booster at 12–15 months.</td>
                                <td>2 doses.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            
                <h1 class="fonts mt-5">Medications for Pregnant Women</h1>
                <div class="table-responsive">
                    <table class="table table-striped">
                        <thead>
                            <tr>
                                <th>Medication/Supplement</th>
                                <th>Usage</th>
                                <th>Duration/Maximum Dose</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>Folic Acid</td>
                                <td>To prevent neural tube defects, 400-800 mcg daily.</td>
                                <td>From before conception to 12 weeks of pregnancy.</td>
                            </tr>
                            <tr>
                                <td>Iron (Ferrous Sulfate)</td>
                                <td>To prevent anemia, 1 tablet (325 mg) daily.</td>
                                <td>1 tablet per day.</td>
                            </tr>
                            <tr>
                                <td>Calcium</td>
                                <td>For bones and teeth, 1,000-1,300 mg daily.</td>
                                <td>Throughout pregnancy.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>    

    <!-- Request Section -->
    <div id="requestSection" class="content-section" style="display: none;">
        <h1 class="mt-5">Request</h1>
    
        <ul class="nav nav-tabs">
            <li class="nav-item">
                <button class="nav-link" data-tab="requestFormContent" onclick="showRequestTab('requestFormContent')">Request Form</button>
            </li>
            <li class="nav-item">
                <button class="nav-link" data-tab="submittedRequestsTab" onclick="showRequestTab('submittedRequestsTab')">Submitted Requests</button>
            </li>
        </ul>
    
        <div id="requestFormContent" class="tab-content" style="display: block;">
            <form id="requestForm" class="row mt-4">
                <div class="col-md-6 mb-3">
                    <label for="requesterName" class="form-label">Requester Name</label>
                    <input type="text" class="form-control" id="requesterName" placeholder="Enter your name" required>
                </div>
                
                <div class="col-md-6 mb-3">
                    <label for="requesterEmail" class="form-label">Email</label>
                    <input type="email" class="form-control" id="requesterEmail" placeholder="Enter your email" required>
                </div>
    
                <div class="col-md-6 mb-3">
                    <label for="requestType" class="form-label">Type of Request</label>
                    <select class="form-select" id="requestType" required>
                        <option value="" disabled selected>Select request type</option>
                        <option value="supplies">Supplies</option>
                        <option value="medicine">Medicine</option>
                        <option value="equipment">Equipment</option>
                        <option value="event">Event</option>
                        <option value="staff">Staff</option>
                    </select>
                </div>
    
                <div class="col-md-6 mb-3">
                    <label for="fileUpload" class="form-label">Upload File (PDF, Word)</label>
                    <input type="file" class="form-control" id="fileUpload" accept=".pdf, .doc, .docx" required>
                </div>
    
                <div class="col-12 mb-3">
                    <label for="requestDescription" class="form-label">Description of Request</label>
                    <textarea class="form-control" id="requestDescription" rows="4" placeholder="Provide details about your request" required></textarea>
                </div>
    
                <div class="col-12 mb-3">
                    <label for="additionalComments" class="form-label">Additional Comments</label>
                    <textarea class="form-control" id="additionalComments" rows="2" placeholder="Any additional information"></textarea>
                </div>
    
                <div class="col-12 flexend">
                    <button type="button" class="btn-primary" onclick="handleSubmitRequest()">Submit Request</button>
                </div>
            </form>
        </div>
          
    
        <div id="submittedRequestsTab" class="tab-content" style="display: none;">
            <h1 class="mt-5">Request List</h1>
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Type</th>
                            <th>Description</th>
                            <th>Status</th>
                            <th>File</th>
                        </tr>
                    </thead>
                    <tbody id="requestListBody">
                        
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Time In/Out Section -->
    <div id="timeInOutSection" class="content-section" style="display: none;">
        <h5 class="mt-3">Time In/Time Out</h5>

        <div class="d-flex mt-2">
          <button id="timeInButton" style="margin: 10px;" onclick="timeIn()">Time In</button>
          <button id="timeOutButton" style="margin: 10px;" onclick="timeOut()">Time Out</button>
        </div>

        <div id="timeRecordsDisplay" class="mt-3 text-center" style="font-size: 0.9rem;"></div>

        <div class="table-container">
            <table id="timeRecordsTable">
                <thead>
                <tr>
                    <th>Date</th>
                    <th>Time In</th>
                    <th>Time Out</th>
                    <th>Total Hours</th>
                </tr>
                </thead>
                <tbody>

                </tbody>
            </table>
        </div>
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
let allResidents = [];
let filteredResidents = [];
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
        case 'residentSection':
            fetchResidentData();
            break;
        case 'appointmentSection':
            populateYearDropdown();
            updateMonthYearDisplay();
            generateCalendarDays();
            break;
        case 'healthcareSection':
            fetchHealthDepartmentEmployees();
            break;
        case 'medicineSection':
            fetchInventory();
            break;
        case 'requestSection':
            fetchRequests();
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
    document.getElementById('residentModal').style.display = 'none';
}

function closeEmployeeModal() {
    document.getElementById('employeeModal').style.display = 'none';
}

function closeReleaseModal() {
    document.getElementById('medicineReleaseModal').style.display = 'none';
}

// ==================== DASHBOARD FUNCTIONS ====================

function fetchDashboardData() {
    const date = document.getElementById('appointmentDatePicker').value;
    fetch(`healthcommittee.php?action=get_dashboard_data&date=${date}`)
        .then(response => response.json())
        .then(data => {
            document.getElementById('totalAppointments').textContent = data.total || 0;
            document.getElementById('pendingAppointments').textContent = data.pending || 0;
            document.getElementById('completedAppointments').textContent = data.completed || 0;
            document.getElementById('canceledAppointments').textContent = data.canceled || 0;
        })
        .catch(error => console.error('Error:', error));
}

// ==================== RESIDENT FUNCTIONS ====================

function fetchResidentData() {
    fetch(`healthcommittee.php?action=get_residents&page=${currentPage}`)
        .then(response => response.json())
        .then(data => {
            allResidents = data.residents;
            filteredResidents = [...allResidents];
            displayResidents(data.total, data.pages);
        })
        .catch(error => {
            console.error('Error:', error);
            Swal.fire('Error', 'Failed to load resident data', 'error');
        });
}

function displayResidents(total, totalPages) {
    const residentListBody = document.getElementById('residentListData');
    residentListBody.innerHTML = '';
    
    if (filteredResidents.length > 0) {
        filteredResidents.forEach(resident => {
            const row = document.createElement('tr');
            row.innerHTML = `
                <td>${resident.id}</td>
                <td>${resident.first_name} ${resident.last_name}</td>
                <td>${resident.mobile_number || 'N/A'}</td>
                <td>${resident.email || 'N/A'}</td>
            `;
            row.addEventListener('click', () => openResidentModal(resident.id));
            residentListBody.appendChild(row);
        });
    } else {
        const row = document.createElement('tr');
        row.innerHTML = '<td colspan="4">No residents found</td>';
        residentListBody.appendChild(row);
    }
    
    document.getElementById('pageInfo').textContent = `Page ${currentPage} of ${totalPages}`;
    document.getElementById('prevPage').disabled = currentPage === 1;
    document.getElementById('nextPage').disabled = currentPage >= totalPages;
}

function openResidentModal(residentId) {
    fetch(`healthcommittee.php?action=get_resident&id=${residentId}`)
        .then(response => response.json())
        .then(resident => {
            if (resident.error) {
                Swal.fire('Error', resident.error, 'error');
                return;
            }
            
            const modalContent = document.getElementById('modalContent');
            modalContent.innerHTML = `
                <div class="resident-details">
                    <h3><i class="fas fa-user"></i> Resident Information</h3>
                    <div class="detail-row">
                        <div class="detail-label">Resident ID:</div>
                        <div class="detail-value">${resident.id}</div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Name:</div>
                        <div class="detail-value">${resident.first_name} ${resident.middle_name || ''} ${resident.last_name}</div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Birthdate:</div>
                        <div class="detail-value">${resident.birthdate || 'N/A'}</div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Age:</div>
                        <div class="detail-value">${resident.age || 'N/A'}</div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Sex:</div>
                        <div class="detail-value">${resident.sex || 'N/A'}</div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Address:</div>
                        <div class="detail-value">${resident.address || 'N/A'}</div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Mobile Number:</div>
                        <div class="detail-value">${resident.mobile_number || 'N/A'}</div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Email:</div>
                        <div class="detail-value">${resident.email || 'N/A'}</div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Emergency Contact:</div>
                        <div class="detail-value">${resident.emergency_contact_name || 'N/A'} (${resident.emergency_contact_number || 'N/A'})</div>
                    </div>
                </div>
            `;
            
            document.getElementById('residentModal').style.display = 'flex';
        })
        .catch(error => {
            console.error('Error:', error);
            Swal.fire('Error', 'Failed to load resident details', 'error');
        });
}

function searchResidents() {
    const searchTerm = document.getElementById('searchResident').value.toLowerCase();
    
    if (searchTerm.trim() === '') {
        filteredResidents = [...allResidents];
    } else {
        filteredResidents = allResidents.filter(resident => {
            return (
                resident.id.toLowerCase().includes(searchTerm) ||
                resident.first_name.toLowerCase().includes(searchTerm) ||
                resident.last_name.toLowerCase().includes(searchTerm) ||
                (resident.mobile_number && resident.mobile_number.toLowerCase().includes(searchTerm)) ||
                (resident.email && resident.email.toLowerCase().includes(searchTerm))
            );
        });
    }
    
    currentPage = 1;
    displayResidents();
}

function prevPage() {
    if (currentPage > 1) {
        currentPage--;
        fetchResidentData();
    }
}

function nextPage() {
    currentPage++;
    fetchResidentData();
}

// ==================== APPOINTMENT FUNCTIONS ====================

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
    fetch(`healthcommittee.php?action=get_appointments&date=${date}`)
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
    
    fetch('healthcommittee.php?action=update_appointment', {
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

// ==================== HEALTHCARE WORKERS FUNCTIONS ====================

function fetchHealthDepartmentEmployees() {
    fetch('healthcommittee.php?action=get_healthcare_workers')
        .then(response => response.json())
        .then(workers => {
            const departmentListBody = document.getElementById('departmentListData');
            departmentListBody.innerHTML = '';
            
            if (workers.length > 0) {
                workers.forEach(worker => {
                    const row = document.createElement('tr');
                    row.innerHTML = `
                        <td>${worker.id}</td>
                        <td>${worker.first_name} ${worker.last_name}</td>
                        <td>${worker.role}</td>
                        <td>${worker.status}</td>
                    `;
                    row.addEventListener('click', () => openEmployeeModal(worker.id));
                    departmentListBody.appendChild(row);
                });
            } else {
                const row = document.createElement('tr');
                row.innerHTML = '<td colspan="4">No employees found in Health Department</td>';
                departmentListBody.appendChild(row);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            Swal.fire('Error', 'Failed to load healthcare workers', 'error');
        });
}

function openEmployeeModal(employeeId) {
    fetch(`healthcommittee.php?action=get_healthcare_worker&id=${employeeId}`)
        .then(response => response.json())
        .then(employee => {
            if (employee.error) {
                Swal.fire('Error', employee.error, 'error');
                return;
            }
            
            const modalContent = document.getElementById('modalEmployeeContent');
            modalContent.innerHTML = `
                <div class="resident-details">
                    <h3><i class="fas fa-user-md"></i> Employee Details</h3>
                    <div class="detail-row">
                        <div class="detail-label">Employee ID:</div>
                        <div class="detail-value">${employee.id}</div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Name:</div>
                        <div class="detail-value">${employee.first_name} ${employee.middle_name || ''} ${employee.last_name}</div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Role:</div>
                        <div class="detail-value">${employee.role}</div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Status:</div>
                        <div class="detail-value">${employee.status}</div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Department:</div>
                        <div class="detail-value">${employee.department}</div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Contact Number:</div>
                        <div class="detail-value">${employee.contact_number || 'N/A'}</div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Email:</div>
                        <div class="detail-value">${employee.email || 'N/A'}</div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Address:</div>
                        <div class="detail-value">${employee.address || 'N/A'}</div>
                    </div>
                </div>
            `;
            
            document.getElementById('employeeModal').style.display = 'flex';
        })
        .catch(error => {
            console.error('Error:', error);
            Swal.fire('Error', 'Failed to load employee details', 'error');
        });
}

// ==================== MEDICINE FUNCTIONS ====================

function showMedicineTab(tabId) {
    document.querySelectorAll('.tab-content .tab-pane').forEach(tab => {
        tab.classList.remove('show', 'active');
    });

    const activeTabContent = document.getElementById(tabId);
    activeTabContent.classList.add('show', 'active');

    document.querySelectorAll('.nav-link').forEach(tab => {
        tab.classList.remove('active');
    });

    const activeTabButton = document.querySelector(`[data-tab="${tabId}"]`);
    activeTabButton.classList.add('active');

    if (tabId === 'medicineTabPane') {
        fetchInventory();
    }
}

function fetchInventory() {
    fetch('healthcommittee.php?action=get_medicines')
        .then(response => response.json())
        .then(medicines => {
            const inventoryList = document.querySelector('#inventoryList tbody');
            inventoryList.innerHTML = '';
            
            if (medicines.length > 0) {
                medicines.forEach(medicine => {
                    const row = document.createElement('tr');
                    row.innerHTML = `
                        <td>${medicine.category}</td>
                        <td>${medicine.name}</td>
                        <td>${medicine.quantity}</td>
                        <td>${medicine.date_added}</td>
                        <td>${medicine.expiration_date}</td>
                        <td>
                            <button class="btn btn-primary btn-sm" onclick="openReleaseModal('${medicine.id}', '${medicine.name}', ${medicine.quantity})">Release</button>
                        </td>
                    `;
                    inventoryList.appendChild(row);
                });
            } else {
                const row = document.createElement('tr');
                row.innerHTML = '<td colspan="6">No medicines found in inventory</td>';
                inventoryList.appendChild(row);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            Swal.fire('Error', 'Failed to load medicine inventory', 'error');
        });
}

function openReleaseModal(medicineId, medicineName, currentQuantity) {
    document.getElementById('residentId').value = '';
    const batchDetails = document.getElementById('batchDetails');
    batchDetails.innerHTML = `
        <div class="stock-row">
            <p style="font-weight: bold;">Medicine: ${medicineName}</p>
            <p style="font-weight: bold;">Available Quantity: ${currentQuantity}</p>
            <input type="hidden" id="medicineId" value="${medicineId}">
            <input type="number" id="releaseQuantity" min="1" max="${currentQuantity}" placeholder="Enter quantity to release" required>
        </div>
    `;
    
    document.getElementById('medicineReleaseModal').style.display = 'flex';
}

function handleMedicineRelease() {
    const medicineId = document.getElementById('medicineId').value;
    const residentId = document.getElementById('residentId').value;
    const quantity = document.getElementById('releaseQuantity').value;
    
    if (!residentId || !quantity) {
        Swal.fire('Error', 'Please fill in all required fields', 'error');
        return;
    }
    
    const formData = new FormData();
    formData.append('medicine_id', medicineId);
    formData.append('resident_id', residentId);
    formData.append('quantity', quantity);
    
    fetch('healthcommittee.php?action=release_medicine', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            Swal.fire('Success', 'Medicine released successfully', 'success');
            closeReleaseModal();
            fetchInventory();
        } else {
            Swal.fire('Error', 'Failed to release medicine', 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        Swal.fire('Error', 'Failed to release medicine', 'error');
    });
}

// ==================== REQUEST FUNCTIONS ====================

function showRequestTab(tabId) {
    document.querySelectorAll('.tab-content').forEach(tabContent => {
        tabContent.style.display = 'none';
    });

    document.querySelectorAll('.nav-link').forEach(tab => {
        tab.classList.remove('active');
    });

    document.getElementById(tabId).style.display = 'block';

    if (tabId === 'requestFormContent') {
        document.querySelector("button[data-tab='requestFormContent']").classList.add('active');
    } else if (tabId === 'submittedRequestsTab') {
        document.querySelector("button[data-tab='submittedRequestsTab']").classList.add('active');
        fetchRequests();
    }
}

function handleSubmitRequest() {
    const requesterName = document.getElementById('requesterName').value;
    const requesterEmail = document.getElementById('requesterEmail').value;
    const requestType = document.getElementById('requestType').value;
    const requestDescription = document.getElementById('requestDescription').value;
    const additionalComments = document.getElementById('additionalComments').value;
    const file = document.getElementById('fileUpload').files[0];
    
    if (!requesterName || !requesterEmail || !requestType || !requestDescription || !file) {
        Swal.fire('Error', 'Please fill in all required fields', 'error');
        return;
    }
    
    const formData = new FormData();
    formData.append('requester_name', requesterName);
    formData.append('requester_email', requesterEmail);
    formData.append('request_type', requestType);
    formData.append('description', requestDescription);
    formData.append('comments', additionalComments);
    formData.append('file', file);
    
    fetch('healthcommittee.php?action=submit_request', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            Swal.fire('Success', 'Request submitted successfully', 'success');
            document.getElementById('requestForm').reset();
            showRequestTab('submittedRequestsTab');
        } else {
            Swal.fire('Error', 'Failed to submit request', 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        Swal.fire('Error', 'Failed to submit request', 'error');
    });
}

function fetchRequests() {
    fetch('healthcommittee.php?action=get_requests')
        .then(response => response.json())
        .then(requests => {
            const requestListBody = document.getElementById('requestListBody');
            requestListBody.innerHTML = '';
            
            if (requests.length > 0) {
                requests.forEach(request => {
                    const row = document.createElement('tr');
                    row.innerHTML = `
                        <td>${request.requester_name}</td>
                        <td>${request.requester_email}</td>
                        <td>${request.request_type}</td>
                        <td>${request.description}</td>
                        <td>${request.status}</td>
                        <td>${request.file_path ? `<a href="${request.file_path}" target="_blank">View File</a>` : 'No file'}</td>
                    `;
                    requestListBody.appendChild(row);
                });
            } else {
                const row = document.createElement('tr');
                row.innerHTML = '<td colspan="6">No requests found</td>';
                requestListBody.appendChild(row);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            Swal.fire('Error', 'Failed to load requests', 'error');
        });
}

// ==================== TIME IN/OUT FUNCTIONS ====================

function timeIn() {
    fetch('healthcommittee.php?action=time_in_out&type=in')
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
    fetch('healthcommittee.php?action=time_in_out&type=out')
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
    fetch('healthcommittee.php?action=get_time_records')
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
    
    // Close modals when clicking outside
    document.getElementById('residentModal').addEventListener('click', function(e) {
        if (e.target === this) {
            closeModal();
        }
    });
    
    document.getElementById('employeeModal').addEventListener('click', function(e) {
        if (e.target === this) {
            closeEmployeeModal();
        }
    });
    
    document.getElementById('medicineReleaseModal').addEventListener('click', function(e) {
        if (e.target === this) {
            closeReleaseModal();
        }
    });
});
</script>
</body>
</html>