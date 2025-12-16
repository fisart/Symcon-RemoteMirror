<?php

declare(strict_types=1);

class RemoteSync extends IPSModule
{
    // Caching the connection details to avoid decrypting on every event
    private $rpcClient = null;
    
    // Caching local ID -> Remote ID mappings to reduce network calls
    private $idMappingCache = [];

    public function Create()
    {
        // Never delete this line!
        parent::Create();

        // Register Properties
        $this->RegisterPropertyBoolean('DebugMode', false);
        $this->RegisterPropertyBoolean('AutoCreate', true);
        $this->RegisterPropertyInteger('CipherVarID', 0);
        $this->RegisterPropertyInteger('KeyVarID', 0);
        $this->RegisterPropertyInteger('LocalRootID', 0);
        $this->RegisterPropertyInteger('RemoteRootID', 0); // Target Parent ID
        $this->RegisterPropertyString('SyncList', '[]');
    }

    public function ApplyChanges()
    {
        // Never delete this line!
        parent::ApplyChanges();

        // 1. Unregister all existing Message Sinks (Clean slate)
        $messages = $this->GetMessageList();
        foreach ($messages as $senderID => $messageID) {
            $this->UnregisterMessage($senderID, VM_UPDATE);
        }

        // 2. Validate Config
        $localRoot = $this->ReadPropertyInteger('LocalRootID');
        if ($localRoot == 0 || !IPS_ObjectExists($localRoot)) {
            $this->SetStatus(IS_INACTIVE);
            return;
        }

        // 3. Process Sync List
        $syncList = json_decode($this->ReadPropertyString('SyncList'), true);
        $count = 0;

        foreach ($syncList as $item) {
            if (!$item['Active']) continue;
            
            $objID = $item['ObjectID'];
            if (!IPS_ObjectExists($objID)) continue;

            // Check if object is actually child of the Local Root
            if (!$this->IsChildOf($objID, $localRoot)) {
                $this->LogDebug("Object $objID is not a child of configured Root $localRoot. Skipping.");
                continue;
            }

            // Recursive Registration
            $this->RecursiveRegister($objID);
            $count++;
        }

        if ($count > 0) {
            $this->SetStatus(IS_ACTIVE);
        } else {
            $this->SetStatus(IS_INACTIVE);
        }
        
        // Clear Cache on Apply
        $this->idMappingCache = [];
    }

    /**
     * Triggered when a registered variable updates
     */
    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        if ($Message == VM_UPDATE) {
            $this->SyncVariable($SenderID, $Data[0]);
        }
    }

    /**
     * Main Logic: Push Value to Remote
     */
    private function SyncVariable(int $localID, $value)
    {
        // 1. Get RPC Connection
        if (!$this->InitConnection()) {
            return;
        }

        // 2. Determine Remote ID (check cache or find via RPC)
        $remoteID = $this->ResolveRemoteID($localID);

        if ($remoteID > 0) {
            // 3. Push Value
            try {
                $this->LogDebug("Pushing Value: $value to Remote ID: $remoteID");
                $this->rpcClient->SetValue($remoteID, $value);
            } catch (Exception $e) {
                $this->LogDebug("Error setting value: " . $e->getMessage());
                // Invalidate cache in case remote object was deleted
                unset($this->idMappingCache[$localID]);
            }
        } else {
            $this->LogDebug("Could not resolve Remote ID for Local ID $localID");
        }
    }

    /**
     * Finds the Remote ID by mirroring the local path structure.
     * Uses Cache first, then RPC.
     */
    private function ResolveRemoteID(int $localID): int
    {
        // Check Cache
        if (isset($this->idMappingCache[$localID])) {
            return $this->idMappingCache[$localID];
        }

        $localRoot = $this->ReadPropertyInteger('LocalRootID');
        $remoteRoot = $this->ReadPropertyInteger('RemoteRootID');

        // Build path relative to root
        // Example: Root=100, Var=105 (path: Root -> Floor -> Room -> Var)
        // Path array: ['Floor', 'Room', 'Var']
        $pathStack = [];
        $currentID = $localID;
        
        while ($currentID != $localRoot) {
            if ($currentID == 0) break; // Safety break
            $name = IPS_GetName($currentID);
            array_unshift($pathStack, $name); // Add to beginning
            $currentID = IPS_GetParent($currentID);
        }

        // Now walk the remote tree
        $currentRemoteID = $remoteRoot;
        $autoCreate = $this->ReadPropertyBoolean('AutoCreate');

        foreach ($pathStack as $index => $nodeName) {
            // Verify connection
            if(!$this->rpcClient) return 0;

            try {
                // Check if child exists on remote
                $childID = @$this->rpcClient->IPS_GetObjectIDByName($nodeName, $currentRemoteID);
            } catch (Exception $e) {
                $childID = 0;
            }

            if ($childID == 0) {
                // Not found
                if ($autoCreate) {
                    $isLeaf = ($index === count($pathStack) - 1); // Is this the variable itself?
                    
                    if ($isLeaf) {
                        // Create Variable
                        $localObj = IPS_GetVariable($localID);
                        $type = $localObj['VariableType'];
                        $this->LogDebug("Creating Remote Variable: $nodeName (Type: $type)");
                        
                        try {
                            $childID = $this->rpcClient->IPS_CreateVariable($type);
                            $this->rpcClient->IPS_SetParent($childID, $currentRemoteID);
                            $this->rpcClient->IPS_SetName($childID, $nodeName);
                        } catch (Exception $e) {
                            $this->LogDebug("Creation failed: " . $e->getMessage());
                            return 0;
                        }
                    } else {
                        // Create Category / Dummy Instance for folder structure
                        // Using Dummy Instance GUID as per user's legacy script
                        $dummyGUID = "{485D0419-BE97-4548-AA9C-C083EB82E61E}";
                        $this->LogDebug("Creating Remote Dummy Instance: $nodeName");
                        
                        try {
                            $childID = $this->rpcClient->IPS_CreateInstance($dummyGUID);
                            $this->rpcClient->IPS_SetParent($childID, $currentRemoteID);
                            $this->rpcClient->IPS_SetName($childID, $nodeName);
                        } catch (Exception $e) {
                            $this->LogDebug("Creation failed: " . $e->getMessage());
                            return 0;
                        }
                    }
                } else {
                    $this->LogDebug("Path incomplete and AutoCreate is OFF. Stopped at: $nodeName");
                    return 0;
                }
            }
            
            // Move down one level
            $currentRemoteID = $childID;
        }

        // Cache the result
        $this->idMappingCache[$localID] = $currentRemoteID;
        return $currentRemoteID;
    }

    /**
     * Recursively registers VM_UPDATE for variables
     */
    private function RecursiveRegister($objectID)
    {
        $obj = IPS_GetObject($objectID);
        
        if ($obj['ObjectType'] == 2) { // Variable
            $this->RegisterMessage($objectID, VM_UPDATE);
        } elseif ($obj['ObjectType'] == 1 || $obj['ObjectType'] == 0) { // Instance or Category
            $children = IPS_GetChildrenIDs($objectID);
            foreach ($children as $child) {
                $this->RecursiveRegister($child);
            }
        }
    }

    private function IsChildOf($childID, $parentID)
    {
        $current = $childID;
        while ($current > 0) {
            $p = IPS_GetParent($current);
            if ($p == $parentID) return true;
            $current = $p;
        }
        return false;
    }

    /**
     * Handles decryption and JSON-RPC setup
     */
    private function InitConnection()
    {
        if ($this->rpcClient !== null) return true;

        $cipherID = $this->ReadPropertyInteger('CipherVarID');
        $keysID = $this->ReadPropertyInteger('KeyVarID');

        if (!IPS_VariableExists($cipherID) || !IPS_VariableExists($keysID)) {
            $this->LogDebug("Credential variables missing.");
            return false;
        }

        $ciphertext = GetValueString($cipherID);
        $cipher_keys = GetValueString($keysID);
        
        // Decryption Logic
        $keyring_hex = @unserialize($cipher_keys);
        if ($keyring_hex === false || !isset($keyring_hex['secret_key'])) {
            $this->LogDebug("Failed to unserialize keys.");
            return false;
        }

        $secret_key = hex2bin($keyring_hex['secret_key']);
        $iv = hex2bin($keyring_hex['iv']);
        $tag = hex2bin($keyring_hex['tag']);

        // PHP 7/8 OpenSSL
        $original_plaintext = openssl_decrypt(
            $ciphertext, 
            $keyring_hex['cipher'], 
            $secret_key, 
            $options=0, 
            $iv, 
            $tag
        );

        if ($original_plaintext === false) {
            $this->LogDebug("Decryption failed.");
            return false;
        }

        $access_data = @unserialize($original_plaintext);
        if ($access_data === false) {
            $this->LogDebug("Failed to unserialize access data.");
            return false;
        }

        // Assume Key 1 based on user script
        $key = 1; 
        if (!isset($access_data[$key])) {
             // Fallback if index 1 doesn't exist, take first available
             $key = array_key_first($access_data);
        }

        $url = 'https://'.$access_data[$key]['User'].":".$access_data[$key]['PW']."@".$access_data[$key]['URL']."/api/";
        
        $this->LogDebug("Initializing RPC Client to: " . $access_data[$key]['URL']);
        
        $this->rpcClient = new SimpleJSONRPC($url);
        return true;
    }

    private function LogDebug($msg)
    {
        if ($this->ReadPropertyBoolean('DebugMode')) {
            $this->SendDebug('RemoteSync', $msg, 0);
        }
    }
}

/**
 * Minimal JSON-RPC Client to avoid external dependencies
 */
class SimpleJSONRPC {
    private $url;

    public function __construct($url) {
        $this->url = $url;
    }

    public function __call($method, $params) {
        $payload = json_encode([
            'jsonrpc' => '2.0',
            'method' => $method,
            'params' => $params,
            'id' => time()
        ]);

        $opts = [
            'http' => [
                'method' => 'POST',
                'header' => 'Content-Type: application/json',
                'content' => $payload,
                'timeout' => 5 // Short timeout to prevent locking
            ],
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false
            ]
        ];

        $context = stream_context_create($opts);
        $result = @file_get_contents($this->url, false, $context);

        if ($result === false) {
            throw new Exception("Connection failed to " . $this->url);
        }

        $response = json_decode($result, true);

        if (isset($response['error'])) {
            throw new Exception($response['error']['message']);
        }

        return $response['result'] ?? null;
    }
}