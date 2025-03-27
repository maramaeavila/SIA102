<?php
session_start();
include 'config.php';

// Verify user is logged in and has the correct role
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

if ($_SESSION['role'] !== 'bhw') {
    header("Location: bhw.php");
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
    <script src="https://cdnjs.cloudflare.com/ajax/libs/limonte-sweetalert2/7.2.0/sweetalert2.all.min.js"></script>
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
        /* Resident Modal Styles */
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
            <a class="nav-link d-flex align-items-center" href="#" onclick="showSection('walkinSection')">
                <i class="fa-solid fa-person-walking mr-2"></i>
                <span>Walk-In</span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link d-flex align-items-center" href="#" onclick="showSection('medicineSection')">
                <i class="fa-solid fa-pills mr-2"></i> 
                <span>Medicine</span>
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

    <!-- Walk-In Section -->
    <div id="walkinSection" class="content-section" style="display: none;">
        <h1 class="mt-5">Walk-In Registration</h1>
        <div class="walkin-form">
            <div class="mb-3">
                <label for="residentIdInput" class="form-label">Resident ID:</label>
                <input type="text" id="residentIdInput" class="form-control" placeholder="Enter Resident ID" required>
            </div>
            <div class="mb-3">
                <label for="healthcareProviderInput" class="form-label">Healthcare Provider:</label>
                <input type="text" id="healthcareProviderInput" class="form-control" placeholder="Enter Healthcare Provider" required>
            </div>
            <div class="flexend">
                <button class="btn btn-primary" onclick="submitWalkinData()">Submit Walk-in</button>
            </div>
        </div>
    
        <div id="walkinDetailsSection" class="mt-5">
            <h1>Walk-ins for Today: <span id="todayWalkinCount">0</span></h1>
            <div class="table-container">
                <table class="walkin-table table table-striped">
                    <thead>
                        <tr>
                            <th>Resident ID</th>
                            <th>Healthcare Provider</th>
                            <th>Time</th>
                        </tr>
                    </thead>
                    <tbody id="walkinTableBody"></tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Medicine Section -->
    <div id="medicineSection" class="content-section" style="display: none;">
        <h1 class="mt-5">Medicine Stocks</h1>
        <ul class="nav nav-tabs" id="medicineTab" role="tablist">
            <li class="nav-item">
                <button class="nav-link active" onclick="showMedicineTab('medicineTabPane')">Medicine & Stocks</button>
            </li>
            <li class="nav-item">
                <button class="nav-link" onclick="showMedicineTab('guidelinesTabPane')">Guidelines</button>
            </li>
        </ul>
    
        <div class="tab-content" id="medicineTabContent">
            <div class="tab-pane fade show active" id="medicineTabPane" role="tabpanel">
                <div id="medicineReleaseModal" class="modal" style="display: none;">
                    <div class="modal-content">
                        <span class="close" onclick="closeReleaseModal()">&times;</span>
                        <h2>Release Medicine</h2>
                        <form id="releaseForm">
                            <div class="mb-3">
                                <label for="residentId" class="form-label">Resident ID:</label>
                                <input type="text" id="residentId" class="form-control" required>
                            </div>
                            <div id="batchDetails"></div>
                            <button type="button" class="btn btn-primary" onclick="handleMedicineRelease()">Release</button>
                        </form>
                    </div>
                </div>
                
                <div class="table-container mt-3">
                    <table id="inventoryList" class="table table-striped">
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
            
            <div class="tab-pane fade" id="guidelinesTabPane" role="tabpanel">
                <!-- Your guidelines content here -->
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
        <span class="close" id="cancelLogout">&times;</span>  
        <h2>Confirm Logout</h2>
        <p>Are you sure you want to logout?</p>
        <div class="modal-actions flexend">
            <button id="confirmLogout" class="btn btn-danger">Logout</button>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
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
        case 'walkinSection':
            displayWalkinsForToday(new Date().toISOString().split('T')[0]);
            break;
        case 'medicineSection':
            fetchInventory();
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

// ==================== RESIDENT MODAL FUNCTIONS ====================

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

// ==================== RESIDENT LIST FUNCTIONS ====================

function fetchResidentData() {
    fetch('bhw.php?action=get_residents')
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
    fetch(`bhw.php?action=get_dashboard_data&date=${date}`)
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
    fetch(`bhw.php?action=get_appointments&date=${date}`)
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
    
    fetch('bhw.php?action=update_appointment', {
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

// ==================== WALK-IN FUNCTIONS ====================

function submitWalkinData() {
    const residentId = document.getElementById('residentIdInput').value.trim();
    const healthcareProvider = document.getElementById('healthcareProviderInput').value.trim();
    
    if (!residentId || !healthcareProvider) {
        Swal.fire('Error', 'Please fill in all fields', 'error');
        return;
    }
    
    const formData = new FormData();
    formData.append('residentId', residentId);
    formData.append('healthcareProvider', healthcareProvider);
    
    fetch('bhw.php?action=submit_walkin', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            Swal.fire('Success', 'Walk-in registered successfully!', 'success');
            document.getElementById('residentIdInput').value = '';
            document.getElementById('healthcareProviderInput').value = '';
            displayWalkinsForToday(new Date().toISOString().split('T')[0]);
        } else {
            Swal.fire('Error', data.message || 'Failed to register walk-in', 'error');
        }
    })
    .catch(error => console.error('Error:', error));
}

function displayWalkinsForToday(date) {
    fetch(`bhw.php?action=get_walkins&date=${date}`)
        .then(response => response.json())
        .then(walkins => {
            const tableBody = document.getElementById('walkinTableBody');
            tableBody.innerHTML = '';
            
            document.getElementById('todayWalkinCount').textContent = walkins.length;
            
            if (walkins.length > 0) {
                walkins.forEach(walkin => {
                    const row = document.createElement('tr');
                    row.innerHTML = `
                        <td>${walkin.resident_id}</td>
                        <td>${walkin.healthcare_provider}</td>
                        <td>${walkin.time}</td>
                    `;
                    tableBody.appendChild(row);
                });
            } else {
                const row = document.createElement('tr');
                row.innerHTML = '<td colspan="3">No walk-ins today</td>';
                tableBody.appendChild(row);
            }
        })
        .catch(error => console.error('Error:', error));
}

// ==================== MEDICINE FUNCTIONS ====================

function fetchInventory() {
    fetch('bhw.php?action=get_inventory')
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
    
    fetch('bhw.php?action=release_medicine', {
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

// ==================== TIME IN/OUT FUNCTIONS ====================

function timeIn() {
    fetch('bhw.php?action=time_in_out&type=in')
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
    fetch('bhw.php?action=time_in_out&type=out')
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
    fetch('bhw.php?action=get_time_records')
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
});
</script>
</body>
</html>