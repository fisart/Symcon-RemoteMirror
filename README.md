Module Documentation: IP-Symcon RemoteSync
<<<<<<< HEAD

Overview
=======
1. Overview

>>>>>>> 29c50f97cdb1b672afe8a571b91dd6b2ff45d7dc
The RemoteSync module provides a high-performance, bidirectional synchronization solution for distributed IP-Symcon environments. It allows a "Local" system to mirror variable structures to a "Remote" system in real-time, while enabling the Remote system to control Local hardware via reverse actions.

The module addresses common challenges in distributed systems, such as network latency, high server load during bulk updates ("Thundering Herd"), and configuration fragility.

<<<<<<< HEAD
Solution Architecture
=======
2. Solution Architecture

>>>>>>> 29c50f97cdb1b672afe8a571b91dd6b2ff45d7dc
The solution utilizes a Service-Oriented, Batch-Processing Architecture. Rather than micro-managing individual remote operations via hundreds of API calls, the module delegates logic to intelligence installed on the remote server.

Core Components

The Local Module (The Controller):

Acts as the source of truth.

Monitors local variables for changes.

Buffers events to reduce network traffic.

Initiates connections using an external Secrets Provider.

The Secrets Manager (The Authenticator):

A separate module (SEC) responsible for storing, encrypting, and retrieving credentials.

The Sync module remains stateless regarding credentials, requesting them transiently via SEC_GetSecret() only when a connection is required.

Centralized Remote Helper Scripts (The Workers):

The module installs exactly two PHP scripts in a central, user-defined category on the Remote Server. These scripts are shared by all incoming sync instances, enabling a clean "Many-to-Many" architecture.

Receiver Script: Accepts JSON data batches. It handles the logic for creating categories, variables, and profiles locally on the remote server to ensure zero-latency execution. It uses a TargetID payload to determine where to place data for specific instances.

Gateway Script: Acts as a single entry point for all reverse control actions, routing commands back to the appropriate originating server.

<<<<<<< HEAD
Data Flow Implementation A. Synchronization Flow (Local → → Remote)
=======
3. Data Flow Implementation
A. Synchronization Flow (Local 
→
→
 Remote)

>>>>>>> 29c50f97cdb1b672afe8a571b91dd6b2ff45d7dc
Event Detection: A local variable changes (e.g., Light Status = True).

Buffering: The module's MessageSink captures the event. Instead of immediate transmission, the data is added to an internal Batch Buffer and a short debounce timer (e.g., 200ms) is started.

Optimization: If other variables change within this window, they are aggregated into the same buffer.

Transmission: Upon timer expiry, the module establishes one single HTTPS connection. It sends the entire buffer payload to the Central Remote Receiver Script.

Payload Structure: ['TargetID' => 12345, 'Batch' => [...data...]]

Remote Execution: The Receiver Script processes the batch locally. It creates missing objects, updates values, and applies profiles. Because execution happens locally on the remote server, "orphaned" variables (created but not named due to network timeouts) are eliminated.

<<<<<<< HEAD
B. Reverse Control Flow (Remote → → Local)
=======
B. Reverse Control Flow (Remote 
→
→
 Local)
>>>>>>> 29c50f97cdb1b672afe8a571b91dd6b2ff45d7dc

User Action: A user operates a switch on the Remote WebFront.

Trigger: The remote variable executes the Central Gateway Script (linked as its Action Script).

Identification: The Gateway Script inspects the Ident of the triggered variable. This Ident contains the specific Local Variable ID and the Server Key.

Callback: Using the Server Key, the Gateway Script retrieves connection details from the remote SEC module and sends a RequestAction command back to the Local System.

Feedback Loop: The Local System executes the hardware action. The resulting status change triggers the Synchronization Flow (A), updating the remote visualization to confirm success.

<<<<<<< HEAD
Functionality & Features Selective Synchronization
=======
4. Functionality & Features
Selective Synchronization

>>>>>>> 29c50f97cdb1b672afe8a571b91dd6b2ff45d7dc
Tree Selection: Users can select individual variables from the local object tree.

Batch Configuration Tools: The configuration form provides "All" and "None" buttons for Sync, Action, and Delete columns, allowing rapid configuration of large object trees without manual clicking.

Centralized Remote Logic & Scalability

Shared Resources: Regardless of how many instances mirror data to the remote server, they all utilize the same two helper scripts located in a central RemoteScriptRootID.

Many-to-Many Support: Multiple local servers can sync to the same remote server, and vice versa. Data separation is handled via unique Target IDs and Credentials.

Auto-Discovery & "Self-Healing"

Structure Mirroring: Automatically replicates the Category and Dummy Instance hierarchy.

Profile Replication: Detects and creates Variable Profiles (Icons, Suffixes) on the remote server to ensure visual fidelity.

Script Deployment: The "Install/Update Remote Scripts" function deploys or updates the necessary logic on the remote server. Existing remote variables are automatically migrated to the new logic structure during the next sync.

Robust Error Handling

Try/Fallback Actions: When controlling a local device from the remote side, the system attempts a RequestAction (to trigger hardware). If the local variable is passive (no action defined), the system automatically falls back to SetValue to ensure data consistency.

Lifecycle Management

Remote Deletion: Variables can be flagged for deletion. The module ensures a clean removal of the remote variable and any associated child objects.

<<<<<<< HEAD
Configuration Parameters (form.json) Authentication
=======
5. Configuration Parameters (form.json)
Authentication

>>>>>>> 29c50f97cdb1b672afe8a571b91dd6b2ff45d7dc
Local Secrets Module: Selection of the local SEC instance.

Target Remote Server (Key): Dropdown list of available servers (fetched dynamically).

Reverse Control (Optional)

Remote Secrets Instance ID: The ID of the SEC module installed on the remote system.

Local Server Key: The key name the remote system uses to authenticate back to the local system (e.g., "Home").

Anchors & Setup

Local Root Object: The local Category/Instance to scan for variables.

Remote Data Target ID: The Category ID on the remote server where the mirrored variables will be created.

Remote Script Home ID: A central Category ID on the remote server where the shared Receiver and Gateway scripts will be installed.

Synchronization List

A managed list of detected variables with three options per variable:

Sync (Active): Enables monitoring and mirroring.

R-Action: Enables "Remote Action" (links the variable to the Central Gateway Script).

Del Remote: Flags the variable for deletion on the remote server during the next application of settings.

<<<<<<< HEAD
Technical Implementation Details Performance Optimization
=======
6. Technical Implementation Details
Performance Optimization

>>>>>>> 29c50f97cdb1b672afe8a571b91dd6b2ff45d7dc
Traffic Reduction: JSON-RPC Batching reduces HTTP overhead by approximately 95% during high-load events compared to single-request architectures.

Form Caching: The configuration form caches the scanned object tree. This allows the "Select All" / "Select None" batch tools to operate instantly without re-scanning the entire system.

Optimized Recursion: Variable discovery uses memory-efficient reference passing to handle large object trees (10,000+ objects) quickly.

Stability & Safety

Deferred Initialization: The module uses a Timer-based deferred startup sequence. This prevents "InstanceInterface" timeouts and crashes on busy production systems by ensuring the module is fully registered before attempting network operations.

Initialization Locking: Incoming events are ignored while the configuration is being applied to prevent race conditions.

<<<<<<< HEAD
Security: Credentials are never stored in the module or generated scripts. They are accessed strictly via the Secrets Manager API.
=======
Security: Credentials are never stored in the module or generated scripts. They are accessed strictly via the Secrets Manager API.
>>>>>>> 29c50f97cdb1b672afe8a571b91dd6b2ff45d7dc
