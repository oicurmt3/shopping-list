<?php
// api.php - Updated to handle master list deletion

// --- Configuration ---
define('DATA_DIR', __DIR__ . '/data/');
define('CURRENT_LIST_FILE', DATA_DIR . 'current_list.json');
define('MASTER_LIST_FILE', DATA_DIR . 'master_list.json');

// --- Ensure data directory exists ---
if (!is_dir(DATA_DIR)) {
    if (!mkdir(DATA_DIR, 0755, true)) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Failed to create data directory. Check permissions.']);
        exit;
    }
}

// --- Helper Functions ---
// readJsonFile() remains the same as provided
// writeJsonFile() remains the same as provided
/**
 * Reads JSON data from a file. Returns specified default if file doesn't exist/invalid.
 */
function readJsonFile(string $filepath, $default = []) {
    if (!file_exists($filepath)) { return $default; }
    $jsonContent = file_get_contents($filepath);
    if ($jsonContent === false) { error_log("Failed to read file: " . $filepath); return $default; }
    $data = json_decode($jsonContent, true);
    if (json_last_error() !== JSON_ERROR_NONE) { error_log("Invalid JSON in file: " . $filepath . " - Error: " . json_last_error_msg()); return $default; }
    if (is_array($default) && !is_array($data)) { return $default; }
    return $data;
}

/**
 * Writes data as JSON to a file.
 */
function writeJsonFile(string $filepath, $data): bool {
    $jsonString = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($jsonString === false) { error_log("Failed to encode JSON for file: " . $filepath . " - Error: " . json_last_error_msg()); return false; }
    $result = file_put_contents($filepath, $jsonString, LOCK_EX);
    if ($result === false) { error_log("Failed to write file: " . $filepath . ". Check permissions."); return false; }
    return true;
}


// --- Request Handling ---

header('Content-Type: application/json');
$method = $_SERVER['REQUEST_METHOD'];

// --- GET Request Handling (No changes needed) ---
if ($method === 'GET') {
    try {
        $currentItems = readJsonFile(CURRENT_LIST_FILE, []);
        $masterItemsData = readJsonFile(MASTER_LIST_FILE, []);
        $validatedMasterItems = [];
        if (is_array($masterItemsData)) {
            foreach ($masterItemsData as $item) {
                // Stricter validation: Ensure text is non-empty string, boughtCount is int
                if (is_array($item) && isset($item['text']) && is_string($item['text']) && $item['text'] !== '' && isset($item['boughtCount']) && is_int($item['boughtCount'])) {
                    $validatedMasterItems[] = $item;
                } else {
                    error_log("Invalid item structure in master list, skipping: " . print_r($item, true));
                }
            }
        }
        echo json_encode(['items' => $currentItems, 'masterItemList' => $validatedMasterItems]);
        exit;
    } catch (Exception $e) { /* ... unchanged error handling ... */
        http_response_code(500); error_log("GET Error: " . $e->getMessage()); echo json_encode(['status' => 'error', 'message' => 'Failed to retrieve lists.']); exit;
    }
}
// --- POST Request Handling (Modified) ---
elseif ($method === 'POST') {
    $inputJson = file_get_contents('php://input');
    $inputData = json_decode($inputJson, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400); echo json_encode(['status' => 'error', 'message' => 'Invalid JSON input.']); exit;
    }

    // *** NEW: Check for delete action ***
    if (isset($inputData['action']) && $inputData['action'] === 'deleteMasterItem') {
        if (!isset($inputData['text']) || !is_string($inputData['text']) || $inputData['text'] === '') {
            http_response_code(400); echo json_encode(['status' => 'error', 'message' => 'Missing or invalid item text for deletion.']); exit;
        }

        $textToDelete = $inputData['text'];
        $textToDeleteLower = strtolower($textToDelete);

        try {
            // Read master list
            $masterList = readJsonFile(MASTER_LIST_FILE, []);
            $initialMasterCount = count($masterList);
            // Filter out the item to delete (case-insensitive)
            $updatedMasterList = array_filter($masterList, function ($item) use ($textToDeleteLower) {
                return !(isset($item['text']) && strtolower($item['text']) === $textToDeleteLower);
            });
            // Re-index array if needed (array_values ensures it's a JSON array)
            $updatedMasterList = array_values($updatedMasterList);
            $masterWriteSuccess = writeJsonFile(MASTER_LIST_FILE, $updatedMasterList);

            // Also remove from current list if present
            $currentList = readJsonFile(CURRENT_LIST_FILE, []);
            $initialCurrentCount = count($currentList);
            $updatedCurrentList = array_filter($currentList, function ($item) use ($textToDeleteLower) {
                 return !(isset($item['text']) && strtolower($item['text']) === $textToDeleteLower);
            });
            $updatedCurrentList = array_values($updatedCurrentList);
            $currentWriteSuccess = writeJsonFile(CURRENT_LIST_FILE, $updatedCurrentList);

            if ($masterWriteSuccess && $currentWriteSuccess) {
                 $deleted = count($updatedMasterList) < $initialMasterCount; // Check if actually deleted
                 echo json_encode([
                     'status' => 'success',
                     'message' => $deleted ? 'Item permanently deleted successfully.' : 'Item not found in master list.',
                     'deleted' => $deleted
                 ]);
                 exit;
            } else {
                http_response_code(500);
                error_log("Failed to write one or both files during master item deletion.");
                echo json_encode(['status' => 'error', 'message' => 'Failed to save updated lists after deletion.']);
                exit;
            }
        } catch (Exception $e) {
            http_response_code(500); error_log("Delete Error: " . $e->getMessage()); echo json_encode(['status' => 'error', 'message' => 'An error occurred during item deletion.']); exit;
        }
    }
    // *** END NEW: Check for delete action ***

    // --- Existing save list logic (unchanged from previous correct version) ---
    elseif (isset($inputData['items']) && is_array($inputData['items'])) {
        $newItems = $inputData['items']; // The list state sent by the client

        try {
            // 1. Read previous current list (for bought count logic)
            $previousItems = readJsonFile(CURRENT_LIST_FILE, []);
            $previouslyBoughtIds = [];
            foreach ($previousItems as $pItem) { if (isset($pItem['id']) && isset($pItem['bought']) && $pItem['bought'] === true) { $previouslyBoughtIds[$pItem['id']] = true; } }

            // 2. Read existing master list
            $masterList = readJsonFile(MASTER_LIST_FILE, []);
            $masterMap = [];
            foreach ($masterList as $mItem) { if (isset($mItem['text']) && is_string($mItem['text'])) { $masterMap[strtolower($mItem['text'])] = $mItem; } }

            // 3. Process new items list & update master map/counts
            $itemsToSave = [];
            foreach ($newItems as $newItem) {
                if (!is_array($newItem) || !isset($newItem['id']) || !isset($newItem['text']) || !is_string($newItem['text']) || $newItem['text'] === '' || !isset($newItem['bought']) || !is_bool($newItem['bought'])) { continue; } // Skip invalid
                $itemsToSave[] = $newItem;
                $itemId = $newItem['id']; $itemText = $newItem['text']; $itemTextLower = strtolower($itemText); $isNowBought = $newItem['bought']; $wasPreviouslyBought = isset($previouslyBoughtIds[$itemId]);
                if (isset($masterMap[$itemTextLower])) {
                    if ($isNowBought && !$wasPreviouslyBought) { $masterMap[$itemTextLower]['boughtCount']++; }
                } else {
                    $masterMap[$itemTextLower] = ['text' => $itemText, 'boughtCount' => $isNowBought ? 1 : 0];
                }
            }

            // 4. Write validated current list
            $writeItemsSuccess = writeJsonFile(CURRENT_LIST_FILE, $itemsToSave);
            // 5. Write updated master list
            $writeMasterSuccess = writeJsonFile(MASTER_LIST_FILE, array_values($masterMap));

            // 6. Respond
            if ($writeItemsSuccess && $writeMasterSuccess) {
                echo json_encode(['status' => 'success', 'message' => 'Lists saved successfully.']); exit;
            } else {
                http_response_code(500); error_log("Failed to write one or both list files."); echo json_encode(['status' => 'error', 'message' => 'Failed to save one or both lists.']); exit;
            }
        } catch (Exception $e) { /* ... unchanged error handling ... */
            http_response_code(500); error_log("POST Error (Save): " . $e->getMessage()); echo json_encode(['status' => 'error', 'message' => 'An error occurred while saving lists.']); exit;
        }
    }
    // --- End existing save list logic ---
    else {
        // Neither delete action nor items array found
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Invalid POST request. Missing "items" array or valid "action".']);
        exit;
    }

} else {
    // Handle unsupported methods (GET/POST only)
    http_response_code(405); echo json_encode(['status' => 'error', 'message' => 'Method not allowed.']); exit;
}
?>