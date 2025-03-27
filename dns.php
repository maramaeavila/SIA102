<?php
session_start();
include 'config.php';

// Verify user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// Verify user has the correct role (if needed)
if ($_SESSION['role'] !== 'dns') {
    header("Location: dns.php");
    exit();
}

// Handle logout request
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: login.php");
    exit();
}

// Handle API requests
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    
    try {
        switch ($_GET['action']) {
            case 'get_dashboard_data':
                $date = $_GET['date'] ?? date('Y-m-d');
                $formattedDate = date('m/d/Y', strtotime($date));
                
                $stmt = $conn->prepare("SELECT 
                    COUNT(*) as total,
                    SUM(status = 'PENDING') as pending,
                    SUM(status = 'COMPLETED') as completed,
                    SUM(status = 'CANCELED') as canceled
                    FROM appointments 
                    WHERE appointment_date = ?");
                $stmt->execute([$formattedDate]);
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                
                echo json_encode($result ?: ['total' => 0, 'pending' => 0, 'completed' => 0, 'canceled' => 0]);
                break;
                
            case 'get_residents':
                $stmt = $conn->query("SELECT * FROM residents");
                $residents = $stmt->fetchAll(PDO::FETCH_ASSOC);
                echo json_encode($residents);
                break;
                
            case 'get_appointments':
                $date = $_GET['date'] ?? '';
                $stmt = $conn->prepare("SELECT * FROM appointments WHERE appointment_date = ?");
                $stmt->execute([$date]);
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
                
            case 'submit_walkin':
                $residentId = $_POST['residentId'];
                $healthcareProvider = $_POST['healthcareProvider'];
                $todayDate = date('Y-m-d');
                
                $stmt = $conn->prepare("INSERT INTO walkins (resident_id, healthcare_provider, date) VALUES (?, ?, ?)");
                $stmt->execute([$residentId, $healthcareProvider, $todayDate]);
                echo json_encode(['success' => true, 'walkinId' => $conn->lastInsertId()]);
                break;
                
            case 'get_walkins':
                $date = $_GET['date'] ?? date('Y-m-d');
                $stmt = $conn->prepare("SELECT * FROM walkins WHERE date = ?");
                $stmt->execute([$date]);
                $walkins = $stmt->fetchAll(PDO::FETCH_ASSOC);
                echo json_encode($walkins);
                break;
                
            case 'get_inventory':
                $stmt = $conn->query("SELECT * FROM medicine_inventory");
                $inventory = $stmt->fetchAll(PDO::FETCH_ASSOC);
                echo json_encode($inventory);
                break;
                
            case 'time_in_out':
                $userId = $_SESSION['user_id'];
                $todayDate = date('Y-m-d');
                $currentTime = date('H:i:s');
                
                // Check if already timed in today
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
                
            case 'release_medicine':
                $residentId = $_POST['residentId'];
                $medicineId = $_POST['medicineId'];
                $quantity = $_POST['quantity'];
                
                // Start transaction
                $conn->beginTransaction();
                
                try {
                    // Update inventory
                    $stmt = $conn->prepare("UPDATE medicine_inventory SET quantity = quantity - ? WHERE id = ? AND quantity >= ?");
                    $stmt->execute([$quantity, $medicineId, $quantity]);
                    
                    if ($stmt->rowCount() === 0) {
                        throw new Exception("Insufficient stock or invalid medicine ID");
                    }
                    
                    // Record transaction
                    $stmt = $conn->prepare("INSERT INTO medicine_transactions (medicine_id, resident_id, quantity, date) VALUES (?, ?, ?, ?)");
                    $stmt->execute([$medicineId, $residentId, $quantity, date('Y-m-d')]);
                    
                    $conn->commit();
                    echo json_encode(['success' => true]);
                } catch (Exception $e) {
                    $conn->rollBack();
                    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
                }
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
            max-width: 500px;
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
        .dropdown-menu {
            position: absolute;
            right: 0;
            background: white;
            border: 1px solid #ddd;
            border-radius: 4px;
            padding: 5px 0;
            min-width: 150px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            z-index: 1000;
        }
        .dropdown-item {
            display: block;
            padding: 5px 15px;
            color: #333;
            text-decoration: none;
        }
        .dropdown-item:hover {
            background-color: #f5f5f5;
        }
        .flexend {
            display: flex;
            justify-content: flex-end;
        }
        .modal-actions {
            margin-top: 20px;
            display: flex;
            justify-content: flex-end;
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
            <a class="nav-link d-flex align-items-center" href="#" onclick="showSection('residentSection')">
                <i class="fa-solid fa-house-medical mr-2"></i> 
                <span>Resident List</span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link d-flex align-items-center" href="#" onclick="showSection('appointmentSection')">
                <i class="fa-solid fa-phone mr-2"></i> 
                <span>Appointment</span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link d-flex align-items-center" href="#" onclick="showSection('medicineSection')">
                <i class="fa-solid fa-pills mr-2"></i> 
                <span>Medicine</span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link d-flex align-items-center" href="#" onclick="showSection('medicineReleaseSection')">
                <i class="fa-solid fa-box-open mr-2"></i> 
                Medicine Release
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

    <!-- Resident List Section -->
    <div id="residentSection" class="content-section" style="display: none;">
        <h1 class="mt-5">Resident List</h1>
        <div class="table-container">
            <input type="text" id="searchResident" placeholder="Search residents..." oninput="searchResidents()">
            <table class="table table-striped">
                <thead>
                    <tr>
                        <th>Resident ID</th>
                        <th>Name</th>
                        <th>Mobile Number</th>
                        <th>Email</th>
                    </tr>
                </thead>
                <tbody id="residentListData"></tbody>
            </table>
            <div class="pagination">
                <button id="prevPage" onclick="prevPage()">Previous</button>
                <span id="pageInfo"></span>
                <button id="nextPage" onclick="nextPage()">Next</button>
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
                <select id="yearSelect" class="form-select" style="width: auto; display: inline-block;"></select>
                <div class="calendar-body" id="calendarDays"></div>
            </div>

            <div class="schedule">
                <div class="schedule-header">Appointments</div>
                <table class="appointment-table table table-striped">
                    <thead>
                        <tr>
                            <th>Time</th>
                            <th>Service</th>
                            <th>Provider</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="appointmentTableBody">
                        <tr>
                            <td colspan="5">Select a date to view appointments.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Medicine Section -->
    <div id="medicineSection" class="content-section" style="display: none;">
        <h1 class="mt-5">Medicine Management</h1>
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
                <h1 class="fonts mt-5">Add New Stock</h1>
                <form id="addStockForm" class="mt-4">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="productCategory">Category:</label>
                                <select class="form-control mt-2" id="productCategory" required onchange="handleCategoryChange()">
                                    <option disabled selected>--Select Category--</option>
                                    <option value="Medicine">Medicine</option>
                                    <option value="Contraceptives">Contraceptives</option>
                                    <option value="Vaccine">Vaccine</option>
                                </select>
                            </div>
        
                            <div class="form-group mt-2" id="contraceptivesType" style="display: none;">
                                <label for="productType">Select Contraceptive Product:</label>
                                <select class="form-control mt-2" id="productType" required>
                                    <option disabled selected>--Select Contraceptive--</option>
                                    <option value="Pills">Pills (Oral Contraceptives)</option>
                                    <option value="Condoms">Condoms</option>
                                    <option value="Injectables">Injectables (Depo-Provera)</option>
                                </select>
                            </div>
        
                            <div class="form-group mt-2" id="medicineType" style="display: none;">
                                <label for="productType">Select Medicine:</label>
                                <select class="form-control mt-2" id="productType" required>
                                    <option disabled selected>--Select Medicine--</option>
                                    <option value="Paracetamol">Paracetamol</option>
                                    <option value="Amoxicillin">Amoxicillin</option>
                                    <option value="Cough Syrup">Cough Syrup</option>
                                </select>
                            </div>
        
                            <div class="form-group mt-2" id="vaccineType" style="display: none;">
                                <label for="productType">Select Vaccine:</label>
                                <select class="form-control mt-2" id="productType" required>
                                    <option disabled selected>--Select Vaccine--</option>
                                    <option value="BCG">BCG (Bacillus Calmette-Guerin)</option>
                                    <option value="HepatitisB">Hepatitis B</option>
                                    <option value="Pentavalent">Pentavalent (DPT-HepB-Hib)</option>
                                    <option value="OralPolio">Oral Polio Vaccine (OPV)</option>
                                    <option value="Rotavirus">Rotavirus Vaccine</option>
                                    <option value="JE">Japanese Encephalitis (JE) Vaccine</option>
                                    <option value="Tigdas">Tigdas (Measles) Vaccine</option>
                                </select>
                            </div>
        
                            <div class="form-group mt-2">
                                <label for="productName">Product Name:</label>
                                <input type="text" class="form-control" id="productName" placeholder="Enter product name" required>
                            </div>
                        </div>
        
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="stockQuantity">Quantity:</label>
                                <input type="number" class="form-control" id="stockQuantity" placeholder="Enter quantity to add" required>
                            </div>
                            <div class="form-group">
                                <label for="currentDate">Date Added:</label>
                                <input type="date" class="form-control" id="currentDate" value="" required>
                            </div>
        
                            <div class="form-group">
                                <label for="expirationDate">Expiration Date:</label>
                                <input type="date" class="form-control" id="expirationDate" required>
                            </div>
                        </div>
                    </div>
                    
                    <div class="flexend">
                        <button type="submit" class="btn-primary mt-3">Add Stock</button>
                    </div>    
                </form>

                <div id="medicineReleaseModal" class="modal" style="display: none;">
                    <div class="modal-content">
                      <span class="close" onclick="closeReleaseModal()">&times;</span>
                      <h2>Release Medicine</h2>
                      <form id="releaseForm">
                        <label for="residentId">Resident ID:</label>
                        <input type="text" id="residentId" required><br><br>
                  
                        <div id="batchDetails"></div> 
                  
                        <button type="submit" class="btn btn-primary">Release</button>
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

    <!-- Medicine Release Section -->
    <div id="medicineReleaseSection" class="content-section" style="display: none;">
        <h1 class="mt-5">Medicine Release Records</h1>
        <div class="table-container">
            <table class="table table-striped">
                <thead>
                    <tr>
                        <th>No.</th>
                        <th>Resident ID</th>
                        <th>Medicine Name</th>
                        <th>Quantity Released</th>
                        <th>Release Date</th>
                    </tr>
                </thead>
                <tbody id="medicineReleaseListData"></tbody>
            </table>
            <div class="pagination">
                <button id="prevPageRelease" onclick="prevPageRelease()">Previous</button>
                <span id="pageInfoRelease"></span>
                <button id="nextPageRelease" onclick="nextPageRelease()">Next</button>
            </div>
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

<!-- Resident Modal -->
<div id="residentModal" class="modal" style="display: none;">
    <div class="modal-content" style="max-width: 600px;">
        <span class="close" onclick="closeModal()">&times;</span>
        <div id="modalContent"></div>
    </div>
</div>

<!-- Logout Modal -->
<div id="logoutModal" class="modal" style="display: none;">
    <div class="modal-content">
        <span class="close" onclick="closeLogoutModal()">&times;</span>  
        <h2>Confirm Logout</h2>
        <p>Are you sure you want to logout?</p>
        <div class="modal-actions flexend">
            <button id="confirmLogout" class="btn btn-danger">Logout</button>
            <button class="btn btn-secondary" onclick="closeLogoutModal()">Cancel</button>
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
        case 'medicineSection':
            fetchInventory();
            break;
        case 'medicineReleaseSection':
            fetchMedicineReleases();
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

// ==================== LOGOUT FUNCTIONALITY ====================

document.addEventListener('DOMContentLoaded', function() {
    // Set up logout button
    document.getElementById('confirmLogout').addEventListener('click', function() {
        // Redirect to logout endpoint
        window.location.href = 'dns.php?logout=true';
    });
    
    // Close modal when clicking cancel or X
    document.querySelector('#logoutModal .close').addEventListener('click', closeLogoutModal);
    
    // Initialize dashboard
    showSection('dashboardSection');
    
    // Set current date in date picker
    document.getElementById('appointmentDatePicker').valueAsDate = new Date();
    
    // Set current date in medicine form
    document.getElementById('currentDate').valueAsDate = new Date();
});

// ==================== RESIDENT FUNCTIONS ====================

function fetchResidentData() {
    fetch('dns.php?action=get_residents')
        .then(response => response.json())
        .then(data => {
            allResidents = data;
            filteredResidents = [...allResidents];
            displayResidents();
        })
        .catch(error => {
            console.error('Error:', error);
            Swal.fire('Error', 'Failed to load resident data', 'error');
        });
}

function displayResidents() {
    const residentListBody = document.getElementById('residentListData');
    residentListBody.innerHTML = '';

    const start = (currentPage - 1) * rowsPerPage;
    const end = start + rowsPerPage;
    const paginatedResidents = filteredResidents.slice(start, end);

    if (paginatedResidents.length > 0) {
        paginatedResidents.forEach(resident => {
            const row = document.createElement('tr');
            row.innerHTML = `
                <td>${resident.id}</td>
                <td>${resident.first_name} ${resident.last_name}</td>
                <td>${resident.mobile_number || 'N/A'}</td>
                <td>${resident.email || 'N/A'}</td>
            `;
            row.addEventListener('click', () => openResidentModal(resident));
            residentListBody.appendChild(row);
        });
    } else {
        const row = document.createElement('tr');
        row.innerHTML = '<td colspan="4">No residents found for this page.</td>';
        residentListBody.appendChild(row);
    }
    updatePagination();
}

function openResidentModal(resident) {
    const modal = document.getElementById('residentModal');
    const modalContent = document.getElementById('modalContent');
    
    modalContent.innerHTML = `
        <div class="resident-details">
            <h3><i class="fas fa-user"></i> Resident Information</h3>
            <div class="detail-row">
                <span class="detail-label">ID:</span>
                <span class="detail-value">${resident.id}</span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Full Name:</span>
                <span class="detail-value">${resident.first_name} ${resident.middle_name || ''} ${resident.last_name}</span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Contact:</span>
                <span class="detail-value">${resident.mobile_number || 'N/A'}</span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Birthdate:</span>
                <span class="detail-value">${resident.birthdate || 'N/A'}</span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Gender:</span>
                <span class="detail-value">${resident.gender || 'N/A'}</span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Address:</span>
                <span class="detail-value">${resident.address || 'N/A'}</span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Blood Type:</span>
                <span class="detail-value">${resident.blood_type || 'N/A'}</span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Emergency Contact:</span>
                <span class="detail-value">${resident.emergency_name || 'N/A'} (${resident.emergency_relationship || ''}) - ${resident.emergency_contact || ''}</span>
            </div>
        </div>
    `;
    
    modal.style.display = 'flex';
}

function closeModal() {
    document.getElementById('residentModal').style.display = 'none';
}

function searchResidents() {
    const searchTerm = document.getElementById('searchResident').value.toLowerCase();
    filteredResidents = allResidents.filter(resident => {
        return (
            resident.first_name.toLowerCase().includes(searchTerm) ||
            resident.last_name.toLowerCase().includes(searchTerm) ||
            resident.mobile_number.includes(searchTerm) ||
            resident.email.toLowerCase().includes(searchTerm)
        );
    });
    currentPage = 1;
    displayResidents();
}

function updatePagination() {
    const totalPages = Math.ceil(filteredResidents.length / rowsPerPage);
    document.getElementById('pageInfo').textContent = `Page ${currentPage} of ${totalPages}`;
    document.getElementById('prevPage').disabled = currentPage === 1;
    document.getElementById('nextPage').disabled = currentPage === totalPages;
}

function prevPage() {
    if (currentPage > 1) {
        currentPage--;
        displayResidents();
    }
}

function nextPage() {
    const totalPages = Math.ceil(filteredResidents.length / rowsPerPage);
    if (currentPage < totalPages) {
        currentPage++;
        displayResidents();
    }
}

// ==================== APPOINTMENT FUNCTIONS ====================

function fetchDashboardData() {
    const date = document.getElementById('appointmentDatePicker').value;
    fetch(`dns.php?action=get_dashboard_data&date=${date}`)
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
    fetch(`dns.php?action=get_appointments&date=${date}`)
        .then(response => response.json())
        .then(appointments => {
            const tableBody = document.getElementById('appointmentTableBody');
            tableBody.innerHTML = '';
            
            if (appointments.length > 0) {
                appointments.forEach(appt => {
                    const row = document.createElement('tr');
                    row.innerHTML = `
                        <td>${appt.appointment_time}</td>
                        <td>${appt.health_service}</td>
                        <td>${appt.healthcare_provider}</td>
                        <td>${appt.status}</td>
                        <td>
                            <button class="btn btn-success btn-sm" onclick="updateAppointmentStatus(${appt.id}, 'COMPLETED')">Complete</button>
                            <button class="btn btn-danger btn-sm" onclick="updateAppointmentStatus(${appt.id}, 'CANCELED')">Cancel</button>
                        </td>
                    `;
                    tableBody.appendChild(row);
                });
            } else {
                const row = document.createElement('tr');
                row.innerHTML = '<td colspan="5">No appointments for this date</td>';
                tableBody.appendChild(row);
            }
        })
        .catch(error => console.error('Error:', error));
}

function updateAppointmentStatus(appointmentId, status) {
    const formData = new FormData();
    formData.append('id', appointmentId);
    formData.append('status', status);
    
    fetch('dns.php?action=update_appointment', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            Swal.fire('Success', `Appointment marked as ${status}`, 'success');
            const selectedDate = document.querySelector('.calendar-day.selected')?.dataset.date;
            if (selectedDate) fetchAppointmentsForDate(selectedDate);
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

// ==================== MEDICINE FUNCTIONS ====================

function fetchInventory() {
    fetch('dns.php?action=get_inventory')
        .then(response => response.json())
        .then(inventory => {
            const tableBody = document.querySelector('#inventoryList tbody');
            tableBody.innerHTML = '';
            
            if (inventory.length > 0) {
                inventory.forEach(item => {
                    const row = document.createElement('tr');
                    row.innerHTML = `
                        <td>${item.category}</td>
                        <td>${item.name}</td>
                        <td>${item.quantity}</td>
                        <td>${item.date_added}</td>
                        <td>${item.expiration_date}</td>
                        <td>
                            <button class="btn btn-primary btn-sm" onclick="openReleaseModal(${item.id}, '${item.name}', ${item.quantity})">
                                Release
                            </button>
                        </td>
                    `;
                    tableBody.appendChild(row);
                });
            } else {
                const row = document.createElement('tr');
                row.innerHTML = '<td colspan="6">No inventory items found</td>';
                tableBody.appendChild(row);
            }
        })
        .catch(error => console.error('Error:', error));
}

function openReleaseModal(medicineId, medicineName, currentQuantity) {
    Swal.fire({
        title: `Release ${medicineName}`,
        html: `
            <div class="mb-3">
                <label class="form-label">Resident ID:</label>
                <input type="text" id="swalResidentId" class="swal2-input" placeholder="Resident ID" required>
            </div>
            <div class="mb-3">
                <label class="form-label">Quantity (Available: ${currentQuantity}):</label>
                <input type="number" id="swalQuantity" class="swal2-input" 
                       min="1" max="${currentQuantity}" value="1" required>
            </div>
        `,
        showCancelButton: true,
        confirmButtonText: 'Release',
        preConfirm: () => {
            const residentId = document.getElementById('swalResidentId').value.trim();
            const quantity = parseInt(document.getElementById('swalQuantity').value);
            
            if (!residentId || isNaN(quantity) || quantity < 1 || quantity > currentQuantity) {
                Swal.showValidationMessage('Please enter valid values');
                return false;
            }
            
            return { residentId, quantity, medicineId };
        }
    }).then(result => {
        if (result.isConfirmed) {
            const { residentId, quantity, medicineId } = result.value;
            releaseMedicine(medicineId, residentId, quantity);
        }
    });
}

function releaseMedicine(medicineId, residentId, quantity) {
    const formData = new FormData();
    formData.append('medicineId', medicineId);
    formData.append('residentId', residentId);
    formData.append('quantity', quantity);
    
    fetch('dns.php?action=release_medicine', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            Swal.fire('Success', 'Medicine released successfully', 'success');
            fetchInventory();
        } else {
            Swal.fire('Error', data.message || 'Failed to release medicine', 'error');
        }
    })
    .catch(error => console.error('Error:', error));
}

function showMedicineTab(tabId) {
    document.querySelectorAll('.tab-pane').forEach(tab => {
        tab.classList.remove('show', 'active');
    });
    document.getElementById(tabId).classList.add('show', 'active');
    
    document.querySelectorAll('.nav-link').forEach(tab => {
        tab.classList.remove('active');
    });
    event.target.classList.add('active');
}

function handleCategoryChange() {
    const category = document.getElementById('productCategory').value;
    document.getElementById('contraceptivesType').style.display = 'none';
    document.getElementById('medicineType').style.display = 'none';
    document.getElementById('vaccineType').style.display = 'none';

    if (category === 'Contraceptives') {
        document.getElementById('contraceptivesType').style.display = 'block';
    } else if (category === 'Medicine') {
        document.getElementById('medicineType').style.display = 'block';
    } else if (category === 'Vaccine') {
        document.getElementById('vaccineType').style.display = 'block';
    }
}

document.getElementById('addStockForm').addEventListener('submit', function(event) {
    event.preventDefault();

    const productCategory = document.getElementById('productCategory').value;
    const productType = document.getElementById('productType').value;
    const productName = document.getElementById('productName').value;
    const stockQuantity = document.getElementById('stockQuantity').value;
    const currentDate = document.getElementById('currentDate').value;
    const expirationDate = document.getElementById('expirationDate').value;

    const formData = new FormData();
    formData.append('category', productCategory);
    formData.append('type', productType);
    formData.append('name', productName);
    formData.append('quantity', stockQuantity);
    formData.append('date_added', currentDate);
    formData.append('expiration_date', expirationDate);

    fetch('dns.php?action=add_stock', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            Swal.fire('Success', 'Stock added successfully', 'success');
            document.getElementById('addStockForm').reset();
            fetchInventory();
        } else {
            Swal.fire('Error', data.message || 'Failed to add stock', 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        Swal.fire('Error', 'Failed to add stock', 'error');
    });
});

// ==================== MEDICINE RELEASE FUNCTIONS ====================

let currentPageRelease = 1;
const rowsPerPageRelease = 10;
let medicineReleases = [];
let filteredMedicineReleases = [];

function fetchMedicineReleases() {
    fetch('dns.php?action=get_medicine_releases')
        .then(response => response.json())
        .then(data => {
            medicineReleases = data;
            filteredMedicineReleases = [...medicineReleases];
            displayMedicineReleases();
        })
        .catch(error => console.error('Error:', error));
}

function displayMedicineReleases() {
    const tableBody = document.getElementById('medicineReleaseListData');
    tableBody.innerHTML = '';

    const start = (currentPageRelease - 1) * rowsPerPageRelease;
    const end = start + rowsPerPageRelease;
    const paginatedRecords = filteredMedicineReleases.slice(start, end);

    if (paginatedRecords.length > 0) {
        paginatedRecords.forEach((record, index) => {
            const row = document.createElement('tr');
            row.innerHTML = `
                <td>${start + index + 1}</td>
                <td>${record.resident_id}</td>
                <td>${record.medicine_name}</td>
                <td>${record.quantity}</td>
                <td>${record.date}</td>
            `;
            tableBody.appendChild(row);
        });
    } else {
        const row = document.createElement('tr');
        row.innerHTML = '<td colspan="5">No records found</td>';
        tableBody.appendChild(row);
    }

    updatePaginationRelease();
}

function updatePaginationRelease() {
    const totalPages = Math.ceil(filteredMedicineReleases.length / rowsPerPageRelease);
    document.getElementById('pageInfoRelease').textContent = `Page ${currentPageRelease} of ${totalPages}`;
    document.getElementById('prevPageRelease').disabled = currentPageRelease === 1;
    document.getElementById('nextPageRelease').disabled = currentPageRelease === totalPages;
}

function prevPageRelease() {
    if (currentPageRelease > 1) {
        currentPageRelease--;
        displayMedicineReleases();
    }
}

function nextPageRelease() {
    const totalPages = Math.ceil(filteredMedicineReleases.length / rowsPerPageRelease);
    if (currentPageRelease < totalPages) {
        currentPageRelease++;
        displayMedicineReleases();
    }
}

// ==================== TIME IN/OUT FUNCTIONS ====================

function timeIn() {
    fetch('dns.php?action=time_in_out&type=in')
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
    fetch('dns.php?action=time_in_out&type=out')
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
    fetch('dns.php?action=get_time_records')
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
</script>
</body>
</html>