<?php
// Database configuration
$serverName = "serviggo-sql-server.database.windows.net";
$connectionOptions = array(
    "Database" => "Serviggo_DV",
    "Uid" => "serviggo-admin",
    "PWD" => "0zk@nY@zg@n6!"
);

// Get the JSON input from the request
$json = file_get_contents('php://input');
$data = json_decode($json, true);

// Validate API Key
$expectedApiKey = "abc123";
if (!isset($data['API_Key']) || $data['API_Key'] !== $expectedApiKey) {
    echo json_encode(["status" => "error", "message" => "Invalid API Key"]);
    exit; // Terminate the script
}

// Establish the connection
$conn = sqlsrv_connect($serverName, $connectionOptions);

// Check if connection was successful
if ($conn === false) {
    die(json_encode(["status" => "error", "message" => "Database connection failed", "errors" => sqlsrv_errors()]));
}

// Prepare the SQL query
$sql = "INSERT INTO Table_Form_Translate (Number_Caller, Language_Caller, Number_Target, Language_Target, Company, DateTime_Created, IP_Source)
        VALUES (?, ?, ?, ?, ?, ?, ?)";

// Prepare the statement
$stmt = sqlsrv_prepare($conn, $sql, array(
    $data['Number_Caller'],
    $data['Language_Caller'],
    $data['Number_Target'],
    $data['Language_Target'],
    $data['Company'],
    $data['DateTime_Created'],
    $data['IP_Source']
));

// Execute the statement
if (sqlsrv_execute($stmt)) {
    echo json_encode(["status" => "success", "message" => "Data inserted successfully"]);
} else {
    echo json_encode(["status" => "error", "message" => "Failed to insert data", "errors" => sqlsrv_errors()]);
}

// Close the connection
sqlsrv_close($conn);
?>
