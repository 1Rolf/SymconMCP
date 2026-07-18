<?php

declare(strict_types=1);

/**
 * SymconMCP — guarded fork
 *
 * Changes vs. upstream (symcon/SymconMCP):
 *  - Write mode switch: OFF (0) / ADVISORY (1) / ACTIVE (2)
 *      OFF:      write tools are neither listed nor callable
 *      ADVISORY: write calls are policy-checked and fully audited, but NEVER executed
 *      ACTIVE:   write calls are executed only if the policy allows
 *  - Metadata-driven write policy (deny-by-default, explicit human grant):
 *      a variable is writable only if the central metadata registry (JSON media
 *      document) contains an entry with reviewed=true AND safety=false AND
 *      llm_write="allowed". Missing entry or missing field => DENY.
 *  - Supplementary hard deny list of module GUIDs (class-level safety net).
 *  - Every write requires a "reason" parameter; every attempt (denied, advisory,
 *    executed) is written to an audit log incl. the old value (revert info).
 *  - Removed: rename-object (config write), get-snapshot (context bomb).
 *  - Added: get-aggregated-data (AC_GetAggregatedValues), limit parameter on
 *    get-logged-data, get-write-policy (read-only policy introspection).
 *  - Added (1.2.0): read-only automation introspection — find-automations,
 *    get-automation (full typed detail incl. script/plan source),
 *    find-references-to-object (events exact; scripts/instances best-effort).
 */
class MCP extends IPSModuleStrict
{
    private const MODE_OFF = 0;
    private const MODE_ADVISORY = 1;
    private const MODE_ACTIVE = 2;

    private const ARCHIVE_GUID = '{43192F0B-135B-4CE7-A0A7-1475603F3060}';
    private const UTIL_GUID = '{B69010EA-96D5-46DF-B885-24821B8C8DBD}';

    public function Create(): void
    {
        //Never delete this line!
        parent::Create();

        $this->RegisterPropertyInteger('WriteMode', self::MODE_OFF);
        // Media document (JSON) holding the central metadata registry
        $this->RegisterPropertyInteger('MetadataMediaID', 0);
        // Optional media document (JSONL) for the audit trail; 0 = message log only
        $this->RegisterPropertyInteger('AuditMediaID', 0);
        // Supplementary class-level hard deny list (JSON array of module GUIDs)
        $this->RegisterPropertyString('DenyModuleGUIDs', '[]');

        $this->RegisterHook('mcp');
    }

    public function Destroy(): void
    {
        parent::Destroy();
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
    }

    /**
     * This function will be called by the hook control. Visibility should be protected!
     */
    protected function ProcessHookData(): void
    {
        // Streamable-HTTP conformance:
        // Only POST carries JSON-RPC messages. Clients may additionally open a
        // GET (server-initiated SSE stream, which we don't offer) or send a
        // DELETE (session termination; we are stateless). Per spec both MUST be
        // answered with 405 - NOT with a JSON body. Answering GET with a
        // JSON-RPC *response* body breaks strict clients (Zod validation).
        if (($_SERVER['REQUEST_METHOD'] ?? 'POST') !== 'POST') {
            http_response_code(405);
            header('Allow: POST');
            return;
        }

        $request = json_decode(file_get_contents('php://input'), true);

        $this->SendDebug('MCP Input', json_encode($request), 0);

        if (!is_array($request)) {
            http_response_code(400);
            return;
        }

        // Notifications (no "id") must be accepted with 202 and NO body -
        // returning a JSON-RPC response to a notification is a spec violation
        // that strict clients reject.
        if (!array_key_exists('id', $request)) {
            http_response_code(202);
            return;
        }

        $result = [];
        $method = strval($request['method'] ?? '');
        $this->SendDebug('MCP Method', $method, 0);

        switch ($method) {
            case 'tools/list':
                $result = [
                    'tools' => $this->BuildToolList()
                ];
                break;

            case 'tools/call':
                try {
                    $content = $this->HandleToolCall(
                        strval($request['params']['name'] ?? ''),
                        $request['params']['arguments'] ?? []
                    );
                    $result = [
                        'content' => [
                            [
                                'type' => 'text',
                                'text' => json_encode($content, JSON_INVALID_UTF8_SUBSTITUTE)
                            ]
                        ],
                    ];
                } catch (Throwable $e) {
                    // Tool failures become an MCP error result (isError) instead
                    // of an uncaught exception producing an HTML 500 page, which
                    // would break the transport for strict clients. The LLM sees
                    // the message and can correct its input.
                    $result = [
                        'content' => [
                            [
                                'type' => 'text',
                                'text' => 'Tool error: ' . $e->getMessage()
                            ]
                        ],
                        'isError' => true,
                    ];
                }
                break;

            case 'initialize':
                $result = [
                    'protocolVersion' => '2025-06-18',
                    'capabilities' => [
                        'tools' => [
                            'listChanged' => true
                        ]
                    ],
                    'serverInfo' => [
                        'name' => 'symcon-mcp-guarded',
                        'version' => '1.2.0'
                    ]
                ];
                break;

            case 'ping':
                // Spec: ping expects an empty object result.
                $result = new stdClass();
                break;

            default:
                // Unknown request: proper JSON-RPC error instead of a bogus
                // empty result (which strict clients reject).
                header('Content-Type: application/json');
                echo json_encode([
                    'jsonrpc' => '2.0',
                    'id' => $request['id'],
                    'error' => [
                        'code' => -32601,
                        'message' => 'Method not found: ' . $method
                    ]
                ]);
                return;
        }

        header('Content-Type: application/json');

        $this->SendDebug('MCP Result', json_encode($result), 0);

        echo json_encode([
            'result' => $result,
            'jsonrpc' => '2.0',
            'id' => $request['id']
        ]);
    }

    // =========================================================================
    // Tool list
    // =========================================================================

    private function BuildToolList(): array
    {
        $mode = $this->ReadPropertyInteger('WriteMode');

        $tools = [
            [
                'name' => 'get-object',
                'title' => 'Get Object Info',
                'description' => 'Get the object info for the Symcon object with the given object ID. The output matches that of IPS_GetObject.',
                'inputSchema' => $this->Schema(['objectID' => ['type' => 'number']], ['objectID']),
            ],
            [
                'name' => 'get-name',
                'title' => 'Get Object Name',
                'description' => 'Get the name of the Symcon object with the given object ID.',
                'inputSchema' => $this->Schema(['objectID' => ['type' => 'number']], ['objectID']),
            ],
            [
                'name' => 'find-objects',
                'title' => 'Find Symcon Objects by using different kinds of filters',
                'description' => <<<DESC
Search the ENTIRE Symcon object tree in a single call. This search is global and
complete: an empty result means no matching object exists anywhere — do NOT
verify by walking the tree with get-children afterwards.

Filters (combined with AND):
- types: array of object types (0: category, 1: instance, 2: variable, 3: script, 4: event, 5: media, 6: link)
- name: case-insensitive partial match on the object name
- usage: "temperature" lists all temperature objects
- lastUpdate: object with optional "from"/"to" Unix timestamps filtering by last update (scripts: last execution)
- limit: max results to return (default 50)

Each hit is compact: ObjectID, ObjectName, ObjectType, ParentID and
LocationPath (the names of its ancestors, root first, e.g.
"Haus / EG / Wohnzimmer") — use LocationPath to determine which room an object
belongs to instead of traversing the tree. For full details on a specific hit,
call get-object with its ObjectID.

The response contains totalMatches and truncated. If truncated is true, refine
the filters (e.g. a more specific name) instead of paging through the tree.

Note: room names usually appear in an object's LocationPath, not in its own
name. To find "temperature in the living room", search name "temperatur" and
pick the hit whose LocationPath contains the room.
DESC,
                'inputSchema' => $this->Schema([
                    'types' => ['type' => 'array', 'items' => ['type' => 'number']],
                    'name' => ['type' => 'string'],
                    'usage' => ['type' => 'string'],
                    'lastUpdate' => [
                        'type' => 'object',
                        'properties' => [
                            'from' => ['type' => 'number', 'description' => 'Unix timestamp in seconds'],
                            'to' => ['type' => 'number', 'description' => 'Unix timestamp in seconds']
                        ],
                        'required' => [],
                        'additionalProperties' => false
                    ],
                    'limit' => ['type' => 'number', 'description' => 'Maximum number of results (default 50)']
                ], []),
            ],
            [
                'name' => 'get-children',
                'title' => 'Get Children',
                'description' => 'Get the object info for all children of the Symcon object with the given object ID. Use this to inspect ONE known object, not to search: for finding objects anywhere in the tree, use find-objects instead (it searches globally in a single call). Required: You must provide a valid numeric objectID.',
                'inputSchema' => $this->Schema([
                    'objectID' => ['type' => 'number', 'description' => 'The numeric ID of the parent Symcon object. This is a required integer identifier. Must be a valid objectID from the system.']
                ], ['objectID']),
            ],
            [
                'name' => 'get-value',
                'title' => 'Get Value',
                'description' => 'Get the current value of the Symcon variable with the given object ID. Required: You must provide a valid numeric objectID. The function returns the formatted value by default. The formatted value is a human-readable string that includes relevant annotations such as units (e.g., \'25.5 °C\', \'78.2 °F\') or date/time formats (e.g., \'10:30 AM\'). This formatted output is intended for direct display or for programmatic parsing of units or other metadata. As such, the formatted value should usually preferred unless specific constellations, like debugging, require the raw value. In such a special scenario, the raw value can be requested by setting "raw" to true.',
                'inputSchema' => $this->Schema([
                    'objectID' => ['type' => 'number', 'description' => 'The numeric ID of the Symcon variable. This is a required integer identifier. Must be a valid objectID from the system.'],
                    'raw' => ['type' => 'boolean', 'description' => 'If true, the raw value is returned, otherwise the formatted value, including annotations like units or date/time formatting based on the variable presentation.']
                ], ['objectID']),
            ],
            [
                'name' => 'current-time',
                'title' => 'Get Current Time',
                'description' => 'Returns the current Unix timestamp in seconds since January 1, 1970 UTC.',
                'inputSchema' => $this->Schema([], []),
            ],
            [
                'name' => 'get-logged-data',
                'title' => 'Get Logged Variable Data',
                'description' => <<<DESC
Get historical raw logged data for a Symcon variable within a time range.
Returns logged values newest first, capped by "limit" (default 1000, max 10000).
For long ranges prefer get-aggregated-data; use this only for narrow windows.

TIME RANGE — do NOT compute Unix timestamps yourself. Two ways:
1. Preferred for relative ranges: set "period" to one of today, yesterday,
   last-24h, this-week, last-week, last-7-days, this-month, last-month.
   The server resolves exact boundaries against its own clock. When "period"
   is set, "from"/"to" are ignored.
2. For custom ranges: set "from"/"to" as ISO 8601 datetimes
   (e.g. "2026-07-10T00:00:00") or relative expressions (e.g. "-7 days",
   "yesterday"). The server converts them. Plain Unix-second numbers still work.

The response echoes the resolved window (resolvedFrom/resolvedTo, ISO 8601) so
you can state the exact period you queried. Every entry has a FormattedTime.
DESC,
                'inputSchema' => $this->Schema([
                    'variableID' => ['type' => 'number', 'description' => 'The numeric ID of the Symcon variable to get logged data from'],
                    'period' => ['type' => 'string', 'enum' => ['today', 'yesterday', 'last-24h', 'this-week', 'last-week', 'last-7-days', 'this-month', 'last-month'], 'description' => 'Relative period; server resolves exact boundaries. Overrides from/to.'],
                    'from' => ['type' => 'string', 'description' => 'Range start as ISO 8601 (e.g. "2026-07-10T00:00:00"), a relative expression (e.g. "-7 days"), or a Unix-second number. Ignored if "period" is set.'],
                    'to' => ['type' => 'string', 'description' => 'Range end, same formats as "from". Ignored if "period" is set.'],
                    'limit' => ['type' => 'number', 'description' => 'Maximum number of records to return (default 1000, max 10000)']
                ], ['variableID']),
            ],
            [
                'name' => 'get-aggregated-data',
                'title' => 'Get Aggregated Variable Data',
                'description' => <<<DESC
Get aggregated historical data (min/max/avg per bucket) for a logged Symcon variable.
Preferred for long ranges (weeks/months). Aggregation levels: 0 = hourly, 1 = daily,
2 = weekly, 3 = monthly, 4 = yearly. Returns buckets newest first, capped by "limit"
(default 1000).

TIME RANGE — do NOT compute Unix timestamps yourself. Two ways:
1. Preferred for relative ranges: set "period" to one of today, yesterday,
   last-24h, this-week, last-week, last-7-days, this-month, last-month.
   The server resolves exact boundaries against its own clock. When "period"
   is set, "from"/"to" are ignored. For "yesterday's min/max" use
   period="yesterday" with level=1 — this returns exactly one daily bucket.
2. For custom ranges: set "from"/"to" as ISO 8601 datetimes or relative
   expressions (e.g. "-7 days"). Plain Unix-second numbers still work.

The response echoes the resolved window (resolvedFrom/resolvedTo, ISO 8601).
Each bucket carries FormattedTime, plus FormattedMinTime/FormattedMaxTime for
the moments the min and max actually occurred.
DESC,
                'inputSchema' => $this->Schema([
                    'variableID' => ['type' => 'number', 'description' => 'The numeric ID of the logged Symcon variable'],
                    'level' => ['type' => 'number', 'description' => 'Aggregation level: 0=hourly, 1=daily, 2=weekly, 3=monthly, 4=yearly'],
                    'period' => ['type' => 'string', 'enum' => ['today', 'yesterday', 'last-24h', 'this-week', 'last-week', 'last-7-days', 'this-month', 'last-month'], 'description' => 'Relative period; server resolves exact boundaries. Overrides from/to.'],
                    'from' => ['type' => 'string', 'description' => 'Range start as ISO 8601, a relative expression (e.g. "-7 days"), or a Unix-second number. Ignored if "period" is set.'],
                    'to' => ['type' => 'string', 'description' => 'Range end, same formats as "from". Ignored if "period" is set.'],
                    'limit' => ['type' => 'number', 'description' => 'Maximum number of buckets to return (default 1000, max 10000)']
                ], ['variableID', 'level']),
            ],
            [
                'name' => 'get-status-log',
                'title' => 'Get Messages from Status Log',
                'description' => <<<DESC
Get the latest status log messages
Can be filtered by message type, by using the 'types' array. If types is not set, all messages are returned. Possible values are:
- 0: Default messages
- 1: Success messages
- 2: Notifications
- 3: Warnings
- 4: Errors
- 5: Debug messages
- 6: Custom Messages
DESC,
                'inputSchema' => $this->Schema([
                    'types' => ['type' => 'array', 'items' => ['type' => 'number'], 'description' => 'Array of message types to filter by']
                ], []),
            ],
            [
                'name' => 'find-in-registry',
                'title' => 'Find Devices in the Semantic Registry',
                'description' => <<<DESC
Query the human-curated semantic registry of this home. This is the
AUTHORITATIVE source for which room a device belongs to and what type it is —
for room-based questions ("temperature in the living room") prefer this tool
over find-objects and tree traversal.

Filters (combined with AND, all optional, case-insensitive partial match):
- room: room/area name (e.g. "Wohnzimmer")
- type: device type (e.g. "temperature_sensor", "plug")
- name: matches the entry's name_hint

Each hit contains the ObjectID (use it directly with get-value /
get-logged-data / get-aggregated-data), name_hint, type, room and whether the
entry is human-reviewed.

Note: the registry may not cover every device yet. An empty result means "not
catalogued", not necessarily "does not exist" — fall back to ONE find-objects
call in that case. If both are empty, the device does not exist: say so
honestly instead of exploring the tree.
DESC,
                'inputSchema' => $this->Schema([
                    'room' => ['type' => 'string', 'description' => 'Filter by room/area name (partial match)'],
                    'type' => ['type' => 'string', 'description' => 'Filter by device type (partial match)'],
                    'name' => ['type' => 'string', 'description' => 'Filter by name_hint (partial match)'],
                    'limit' => ['type' => 'number', 'description' => 'Maximum number of results (default 50)']
                ], []),
            ],
            [
                'name' => 'get-write-policy',
                'title' => 'Get Write Policy for a Variable',
                'description' => 'Check whether the given variable could be switched by the LLM under the current write mode and metadata policy. Read-only: performs no action. Use this before attempting switch-boolean to avoid futile calls.',
                'inputSchema' => $this->Schema([
                    'variableID' => ['type' => 'number', 'description' => 'The numeric ID of the Symcon variable to check']
                ], ['variableID']),
            ],
            [
                'name' => 'find-automations',
                'title' => 'Find Automations (Events, Scripts, Plans)',
                'description' => <<<DESC
Search ALL automations of this Symcon installation in a single call: events
(triggered, cyclic, weekly schedule) as well as PHP scripts, flow plans
(Ablaufplan) and logic plans (Logikplan). This search is global and complete:
an empty result means no matching automation exists anywhere — do NOT verify
by walking the tree.

Filters (combined with AND, all optional):
- kinds: array of automation kinds:
    event.trigger   = event fired by a variable (on update/change/limit/value)
    event.cyclic    = time-cyclic event (timer)
    event.schedule  = weekly schedule event (Wochenplan)
    script.php      = PHP script
    script.flow     = flow plan (Ablaufplan)
    script.workflow = logic plan (Logikplan)
- name: case-insensitive partial match on the automation name
- active: true/false — matches only events with that active state
  (scripts/plans have no active flag and are NOT filtered by this)
- limit: max results to return (default 50)

Each hit is compact: ObjectID, Name, Kind, Active (null for scripts/plans),
ParentID, LocationPath. For full details call get-automation with the
ObjectID. For the question "which automations touch object X?" use
find-references-to-object instead.
DESC,
                'inputSchema' => $this->Schema([
                    'kinds' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => ['event.trigger', 'event.cyclic', 'event.schedule', 'script.php', 'script.flow', 'script.workflow']], 'description' => 'Automation kinds to include; omit for all'],
                    'name' => ['type' => 'string', 'description' => 'Case-insensitive partial match on the name'],
                    'active' => ['type' => 'boolean', 'description' => 'Filter events by active state; scripts/plans are unaffected'],
                    'limit' => ['type' => 'number', 'description' => 'Maximum number of results (default 50)']
                ], []),
            ],
            [
                'name' => 'get-automation',
                'title' => 'Get Automation Details',
                'description' => <<<DESC
Get the full, typed description of ONE automation (event, PHP script, flow
plan or logic plan) by its object ID. Read-only: nothing is changed, enabled
or disabled.

For events everything is resolved server-side: trigger type and trigger
variable (with name and LocationPath), conditions (variable/time/date rules
in readable form), the cyclic schedule as text, weekly schedule groups with
weekday names, and LastRun/NextRun as ISO 8601 — never compute timestamps
yourself. The raw event definition is included under "Raw".

For scripts and plans the response contains the FULL source/definition
("Content"; for flow and logic plans this is a JSON structure), the objects
it references (resolved with name and LocationPath), events attached to it,
and LastExecuted/LastUpdated as ISO 8601. Very large content is hard-capped
(ContentTruncated=true if cut).
DESC,
                'inputSchema' => $this->Schema([
                    'objectID' => ['type' => 'number', 'description' => 'The numeric ID of the event, script or plan']
                ], ['objectID']),
            ],
            [
                'name' => 'find-references-to-object',
                'title' => 'Find Automations Referencing an Object',
                'description' => <<<DESC
Answer "which automations touch object X?" for a given object ID. Scans three
sources in one call and reports per source how complete the scan is:

- events (completeness "exact"): every event is checked structurally — as
  trigger, in conditions, attached to the object, or referenced anywhere in
  its definition. An empty events result IS proof that no event uses the
  object.
- scripts (completeness "best-effort"): all PHP scripts, flow plans and logic
  plans are searched for the literal numeric object ID. IDs computed at
  runtime (e.g. via IPS_GetObjectIDByName or variables) cannot be detected —
  an empty result here does NOT prove absence.
- instances (completeness "best-effort"): all instance configurations are
  searched for the literal numeric object ID (finds e.g. a watchdog watching
  the object). Same limitation as scripts.

Each match is compact (ObjectID, Name, Kind or ModuleName, LocationPath,
roles or match count). Use get-automation on a match for details.
DESC,
                'inputSchema' => $this->Schema([
                    'objectID' => ['type' => 'number', 'description' => 'The numeric ID of the object to find references to'],
                    'limit' => ['type' => 'number', 'description' => 'Maximum matches per source (default 100)']
                ], ['objectID']),
            ]
        ];

        // Write tools are only exposed in ADVISORY/ACTIVE mode.
        if ($mode !== self::MODE_OFF) {
            $modeNote = ($mode === self::MODE_ADVISORY)
                ? 'The server currently runs in ADVISORY mode: the call is policy-checked and audited, but NOT executed. The response will contain advisory=true and a message describing what would have happened.'
                : 'The server currently runs in ACTIVE mode: the call is executed if and only if the metadata policy allows it.';
            $tools[] = [
                'name' => 'switch-boolean',
                'title' => 'Switch Tool',
                'description' => 'Switch a Symcon variable to a specified boolean value. A short human-readable "reason" is mandatory and will be written to the audit log. Only variables explicitly granted in the metadata registry (reviewed=true, safety=false, llm_write="allowed") can be switched; everything else is denied by default. ' . $modeNote,
                'inputSchema' => $this->Schema([
                    'variableID' => ['type' => 'number'],
                    'value' => ['type' => 'boolean'],
                    'reason' => ['type' => 'string', 'description' => 'Why this switch is requested. Mandatory; written to the audit log.']
                ], ['variableID', 'value', 'reason']),
            ];
        }

        return $tools;
    }

    // =========================================================================
    // Tool calls
    // =========================================================================

    private function HandleToolCall(string $name, array $args): array
    {
        switch ($name) {
            case 'get-name':
                return [
                    'name' => IPS_GetName(intval($args['objectID']))
                ];

            case 'get-object':
                return [
                    'info' => IPS_GetObject(intval($args['objectID']))
                ];

            case 'find-objects':
                return $this->FindObjects($args);

            case 'get-children':
                $result = [];
                foreach (IPS_GetChildrenIDs(intval($args['objectID'])) as $childID) {
                    $result[] = IPS_GetObject($childID);
                }
                return [
                    'children' => $result
                ];

            case 'get-value':
                $objectID = intval($args['objectID']);
                return [
                    'value' => ($args['raw'] ?? false) ? GetValue($objectID) : GetValueFormatted($objectID)
                ];

            case 'current-time':
                $timestamp = time();
                return [
                    'timestamp' => $timestamp,
                    'formatted' => date('c', $timestamp)
                ];

            case 'get-logged-data':
                $limit = $this->ClampLimit($args['limit'] ?? 1000);
                [$from, $to] = $this->ResolveTimeRange($args);
                $loggedData = AC_GetLoggedValues(
                    $this->GetArchiveID(),
                    intval($args['variableID']),
                    $from,
                    $to,
                    $limit
                );
                foreach ($loggedData as &$entry) {
                    $entry['FormattedTime'] = date('c', $entry['TimeStamp']);
                }
                unset($entry);
                return [
                    'resolvedFrom' => date('c', $from),
                    'resolvedTo' => date('c', $to),
                    'data' => $loggedData
                ];

            case 'get-aggregated-data':
                $limit = $this->ClampLimit($args['limit'] ?? 1000);
                [$from, $to] = $this->ResolveTimeRange($args);
                $aggregated = AC_GetAggregatedValues(
                    $this->GetArchiveID(),
                    intval($args['variableID']),
                    intval($args['level']),
                    $from,
                    $to,
                    $limit
                );
                foreach ($aggregated as &$entry) {
                    $entry['FormattedTime'] = date('c', $entry['TimeStamp']);
                    if (isset($entry['MinTime'])) {
                        $entry['FormattedMinTime'] = date('c', $entry['MinTime']);
                    }
                    if (isset($entry['MaxTime'])) {
                        $entry['FormattedMaxTime'] = date('c', $entry['MaxTime']);
                    }
                }
                unset($entry);
                return [
                    'resolvedFrom' => date('c', $from),
                    'resolvedTo' => date('c', $to),
                    'data' => $aggregated
                ];

            case 'get-status-log':
                return $this->GetStatusLog($args);

            case 'find-in-registry':
                return $this->FindInRegistry($args);

            case 'get-write-policy':
                $mode = $this->ReadPropertyInteger('WriteMode');
                $policy = $this->CheckWritePolicy(intval($args['variableID']));
                return [
                    'mode' => $this->ModeName($mode),
                    'allowed' => $policy['allowed'],
                    'reason' => $policy['reason']
                ];

            case 'find-automations':
                return $this->FindAutomations($args);

            case 'get-automation':
                return $this->GetAutomation($args);

            case 'find-references-to-object':
                return $this->FindReferencesToObject($args);

            case 'switch-boolean':
                return $this->SwitchBoolean($args);

            default:
                throw new Exception('Tool not found');
        }
    }

    private function SwitchBoolean(array $args): array
    {
        $mode = $this->ReadPropertyInteger('WriteMode');
        if ($mode === self::MODE_OFF) {
            return ['success' => false, 'error' => 'Write access is disabled (mode OFF).'];
        }

        $variableID = intval($args['variableID'] ?? 0);
        $value = boolval($args['value'] ?? false);
        $llmReason = trim(strval($args['reason'] ?? ''));

        if ($llmReason === '') {
            return ['success' => false, 'error' => 'Parameter "reason" is mandatory for every write.'];
        }

        $this->SendDebug('Switch Variable', json_encode($args), 0);

        $policy = $this->CheckWritePolicy($variableID);
        $exists = IPS_VariableExists($variableID);
        $oldValue = $exists ? GetValue($variableID) : null;

        $audit = [
            'Tool' => 'switch-boolean',
            'Mode' => $this->ModeName($mode),
            'VariableID' => $variableID,
            'VariableName' => $exists ? IPS_GetName($variableID) : '(unknown)',
            'OldValue' => $oldValue,
            'RequestedValue' => $value,
            'LLMReason' => $llmReason,
            'PolicyAllowed' => $policy['allowed'],
            'PolicyReason' => $policy['reason']
        ];

        if (!$policy['allowed']) {
            $audit['Verdict'] = 'DENIED';
            $this->Audit($audit);
            return ['success' => false, 'error' => 'Denied by policy: ' . $policy['reason']];
        }

        if ($mode === self::MODE_ADVISORY) {
            $audit['Verdict'] = 'ADVISORY_NOT_EXECUTED';
            $this->Audit($audit);
            return [
                'success' => false,
                'advisory' => true,
                'message' => sprintf(
                    'Advisory mode: not executed. Would switch %d ("%s") from %s to %s. The request was audited.',
                    $variableID,
                    IPS_GetName($variableID),
                    json_encode($oldValue),
                    json_encode($value)
                )
            ];
        }

        // MODE_ACTIVE
        RequestAction($variableID, $value);
        $audit['Verdict'] = 'EXECUTED';
        $this->Audit($audit);
        return ['success' => true];
    }

    // =========================================================================
    // Policy (deny-by-default over metadata + supplementary GUID deny list)
    // =========================================================================

    private function CheckWritePolicy(int $variableID): array
    {
        $deny = function (string $reason): array {
            return ['allowed' => false, 'reason' => $reason];
        };

        if (!IPS_VariableExists($variableID)) {
            return $deny('Variable does not exist.');
        }

        // Supplementary net: class-level hard deny by module GUID
        $denyGUIDs = json_decode($this->ReadPropertyString('DenyModuleGUIDs'), true);
        if (is_array($denyGUIDs) && count($denyGUIDs) > 0) {
            $parentID = IPS_GetParent($variableID);
            if ($parentID > 0 && IPS_GetObject($parentID)['ObjectType'] === 1 /* instance */) {
                $moduleID = IPS_GetInstance($parentID)['ModuleInfo']['ModuleID'];
                if (in_array($moduleID, $denyGUIDs, true)) {
                    return $deny('Module class is on the hard deny list.');
                }
            }
        }

        // Primary rule: explicit human grant in the metadata registry.
        // Anything missing => deny. This deliberately covers devices whose
        // criticality is invisible at class level (e.g. a generic plug that
        // powers a heater, or a bare variable without its own module).
        $meta = $this->LoadMetadata();
        $entry = $meta[strval($variableID)] ?? null;

        if ($entry === null) {
            return $deny('No metadata entry for this variable (deny-by-default).');
        }
        if (($entry['reviewed'] ?? false) !== true) {
            return $deny('Metadata entry is not human-reviewed (reviewed != true).');
        }
        if (($entry['safety'] ?? true) === true) {
            return $deny('Variable is safety-critical, or "safety" was not explicitly set to false.');
        }
        if (($entry['llm_write'] ?? 'none') !== 'allowed') {
            return $deny('llm_write is not set to "allowed".');
        }

        return ['allowed' => true, 'reason' => 'Explicit human grant via metadata registry.'];
    }

    private function LoadMetadata(): array
    {
        $mediaID = $this->ReadPropertyInteger('MetadataMediaID');
        if ($mediaID <= 0 || !IPS_MediaExists($mediaID)) {
            return [];
        }
        $json = base64_decode(IPS_GetMediaContent($mediaID));
        $data = json_decode($json, true);
        if (!is_array($data)) {
            $this->LogMessage('MCP: metadata registry is not valid JSON — treating as empty (deny-by-default).', KL_WARNING);
            return [];
        }
        return $data['objects'] ?? [];
    }

    // =========================================================================
    // Audit
    // =========================================================================

    private function Audit(array $entry): void
    {
        $entry['TimeStamp'] = time();
        $entry['FormattedTime'] = date('c');
        $line = json_encode($entry, JSON_UNESCAPED_UNICODE);

        // Always to the Symcon message log …
        $this->LogMessage('MCP-Audit: ' . $line, KL_NOTIFY);

        // … and additionally to a JSONL media document if configured.
        $mediaID = $this->ReadPropertyInteger('AuditMediaID');
        if ($mediaID > 0 && IPS_MediaExists($mediaID)) {
            $content = base64_decode(IPS_GetMediaContent($mediaID));
            IPS_SetMediaContent($mediaID, base64_encode($content . $line . "\n"));
        }
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function FindObjects(array $args): array
    {
        $searchName = strtolower(strval($args['name'] ?? ''));
        $types = $args['types'] ?? [0, 1, 2, 3, 4, 5, 6];
        $usage = strval($args['usage'] ?? '');
        $limit = max(1, min(intval($args['limit'] ?? 50), 200));
        $totalMatches = 0;
        $result = [];

        foreach (json_decode(IPS_GetSnapshot(), true)['objects'] as $index => $object) {
            if (!str_contains(strtolower($object['name']), $searchName)) {
                continue;
            }
            if (!in_array($object['type'], $types)) {
                continue;
            }

            switch ($usage) {
                case 'temperature':
                    if ($object['type'] != 2) {
                        continue 2;
                    }
                    $variableID = intval(substr($index, 2));
                    $presentation = IPS_GetVariablePresentation($variableID);
                    switch ($presentation['PRESENTATION']) {
                        case VARIABLE_PRESENTATION_SLIDER:
                            if ($presentation['USAGE_TYPE'] != 0) {
                                continue 3;
                            }
                            break;

                        case VARIABLE_PRESENTATION_VALUE_PRESENTATION:
                            if ($presentation['USAGE_TYPE'] != 1) {
                                continue 3;
                            }
                            break;

                        default:
                            continue 3;
                    }
                    break;

                default:
                    // no usage filter or unknown usage
                    break;
            }

            if (isset($args['lastUpdate'])) {
                $from = $args['lastUpdate']['from'] ?? null;
                $to = $args['lastUpdate']['to'] ?? null;
                $objectID = intval(substr($index, 2));
                $lastUpdate = null;
                switch ($object['type']) {
                    case 2: // Variable
                        $lastUpdate = IPS_GetVariable($objectID)['VariableUpdated'];
                        break;

                    case 3: // Script
                        $lastUpdate = IPS_GetScript($objectID)['ScriptExecuted'];
                        break;

                    default:
                        // For other object types, last update is not defined, skip
                        continue 2;
                }
                if (isset($from) && $lastUpdate < $from) {
                    continue;
                }
                if (isset($to) && $lastUpdate > $to) {
                    continue;
                }
            }
            $objectID = intval(substr($index, 2));
            $totalMatches++;
            if (count($result) < $limit) {
                $result[] = [
                    'ObjectID' => $objectID,
                    'ObjectName' => $object['name'],
                    'ObjectType' => $object['type'],
                    'ParentID' => IPS_GetParent($objectID),
                    'LocationPath' => $this->GetLocationPath($objectID)
                ];
            }
        }
        return [
            'objects' => $result,
            'totalMatches' => $totalMatches,
            'truncated' => $totalMatches > count($result)
        ];
    }

    /**
     * Build the ancestor name chain for an object, root first, e.g.
     * "Haus / EG / Wohnzimmer". Depth-capped; the object itself is excluded.
     */
    private function GetLocationPath(int $objectID): string
    {
        $names = [];
        $current = IPS_GetParent($objectID);
        $depth = 0;
        while ($current > 0 && $depth < 10) {
            $names[] = IPS_GetName($current);
            $current = IPS_GetParent($current);
            $depth++;
        }
        return implode(' / ', array_reverse($names));
    }

    private function GetStatusLog(array $args): array
    {
        $types = $args['types'] ?? [0, 1, 2, 3, 4, 5, 6];
        $messages = [];

        foreach ($types as $type) {
            $typeMessages = UC_GetLastLogMessages(IPS_GetInstanceListByModuleID(self::UTIL_GUID)[0], intval($type));
            foreach ($typeMessages as &$message) {
                $message['Type'] = $type;
                $message['FormattedTime'] = date('c', $message['TimeStamp']);
            }
            $messages = array_merge($messages, $typeMessages);
        }

        usort($messages, function ($a, $b) {
            return $b['TimeStamp'] <=> $a['TimeStamp'];
        });

        return [
            'data' => $messages
        ];
    }

    private function GetArchiveID(): int
    {
        return IPS_GetInstanceListByModuleID(self::ARCHIVE_GUID)[0];
    }

    /**
     * Search the semantic registry (central JSON media document).
     * The registry is the authoritative source for room/type semantics.
     */
    private function FindInRegistry(array $args): array
    {
        $limit = max(1, min(intval($args['limit'] ?? 50), 200));
        $fRoom = strtolower(trim(strval($args['room'] ?? '')));
        $fType = strtolower(trim(strval($args['type'] ?? '')));
        $fName = strtolower(trim(strval($args['name'] ?? '')));

        $meta = $this->LoadMetadata();
        $result = [];
        $totalMatches = 0;

        foreach ($meta as $objectID => $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $room = strtolower(strval($entry['room'] ?? ''));
            $type = strtolower(strval($entry['type'] ?? ''));
            $name = strtolower(strval($entry['name_hint'] ?? ''));

            if ($fRoom !== '' && !str_contains($room, $fRoom)) {
                continue;
            }
            if ($fType !== '' && !str_contains($type, $fType)) {
                continue;
            }
            if ($fName !== '' && !str_contains($name, $fName)) {
                continue;
            }

            $totalMatches++;
            if (count($result) < $limit) {
                $result[] = [
                    'ObjectID' => intval($objectID),
                    'name_hint' => $entry['name_hint'] ?? '',
                    'type' => $entry['type'] ?? '',
                    'room' => $entry['room'] ?? null,
                    'reviewed' => ($entry['reviewed'] ?? false) === true,
                    'note' => $entry['note'] ?? ''
                ];
            }
        }

        return [
            'entries' => $result,
            'totalMatches' => $totalMatches,
            'truncated' => $totalMatches > count($result),
            'registrySize' => count($meta)
        ];
    }

    // =========================================================================
    // Automation introspection (read-only)
    // =========================================================================

    private function AutomationKindOfEvent(array $event): string
    {
        switch (intval($event['EventType'] ?? -1)) {
            case 0:
                return 'event.trigger';
            case 1:
                return 'event.cyclic';
            case 2:
                return 'event.schedule';
            default:
                return 'event.unknown';
        }
    }

    private function AutomationKindOfScript(array $script): string
    {
        switch (intval($script['ScriptType'] ?? -1)) {
            case 0:
                return 'script.php';
            case 1:
                return 'script.flow'; // Ablaufplan
            case 2:
                return 'script.workflow'; // Logikplan
            default:
                return 'script.unknown';
        }
    }

    /**
     * Compact reference to any object: ID, name, type, LocationPath.
     */
    private function DescribeObjectRef(int $objectID): array
    {
        $object = IPS_GetObject($objectID);
        return [
            'ObjectID' => $objectID,
            'Name' => $object['ObjectName'],
            'ObjectType' => $object['ObjectType'],
            'LocationPath' => $this->GetLocationPath($objectID)
        ];
    }

    /**
     * Global, complete, capped search over all automations:
     * events (all three types) and scripts (PHP / flow plan / logic plan).
     */
    private function FindAutomations(array $args): array
    {
        $limit = max(1, min(intval($args['limit'] ?? 50), 200));
        $fName = strtolower(trim(strval($args['name'] ?? '')));
        $kinds = is_array($args['kinds'] ?? null) ? $args['kinds'] : [];
        $activeFilter = array_key_exists('active', $args) ? boolval($args['active']) : null;

        $candidates = [];
        foreach (IPS_GetEventList() as $eventID) {
            $event = IPS_GetEvent($eventID);
            $candidates[] = [
                'ObjectID' => $eventID,
                'Kind' => $this->AutomationKindOfEvent($event),
                'Active' => boolval($event['EventActive'] ?? false)
            ];
        }
        foreach (IPS_GetScriptList() as $scriptID) {
            $script = IPS_GetScript($scriptID);
            $candidates[] = [
                'ObjectID' => $scriptID,
                'Kind' => $this->AutomationKindOfScript($script),
                'Active' => null // scripts/plans have no active flag
            ];
        }

        $result = [];
        $totalMatches = 0;
        foreach ($candidates as $candidate) {
            $name = IPS_GetName($candidate['ObjectID']);
            if ($fName !== '' && !str_contains(strtolower($name), $fName)) {
                continue;
            }
            if (count($kinds) > 0 && !in_array($candidate['Kind'], $kinds, true)) {
                continue;
            }
            if ($activeFilter !== null && $candidate['Active'] !== null && $candidate['Active'] !== $activeFilter) {
                continue;
            }
            $totalMatches++;
            if (count($result) < $limit) {
                $result[] = [
                    'ObjectID' => $candidate['ObjectID'],
                    'Name' => $name,
                    'Kind' => $candidate['Kind'],
                    'Active' => $candidate['Active'],
                    'ParentID' => IPS_GetParent($candidate['ObjectID']),
                    'LocationPath' => $this->GetLocationPath($candidate['ObjectID'])
                ];
            }
        }

        return [
            'automations' => $result,
            'totalMatches' => $totalMatches,
            'truncated' => $totalMatches > count($result)
        ];
    }

    /**
     * Typed detail of one automation (event or script/plan).
     */
    private function GetAutomation(array $args): array
    {
        $objectID = intval($args['objectID'] ?? 0);
        if (!IPS_ObjectExists($objectID)) {
            throw new Exception('Object ' . $objectID . ' does not exist.');
        }
        $type = IPS_GetObject($objectID)['ObjectType'];
        if ($type === 4) {
            return $this->DescribeEvent($objectID);
        }
        if ($type === 3) {
            return $this->DescribeScript($objectID);
        }
        throw new Exception('Object ' . $objectID . ' is neither an event nor a script/plan (ObjectType ' . $type . '). Use find-automations to locate automations.');
    }

    private function DescribeEvent(int $eventID): array
    {
        $event = IPS_GetEvent($eventID);
        $kind = $this->AutomationKindOfEvent($event);
        $parentID = IPS_GetParent($eventID);

        $out = [
            'ObjectID' => $eventID,
            'Name' => IPS_GetName($eventID),
            'Kind' => $kind,
            'Active' => boolval($event['EventActive'] ?? false),
            'LocationPath' => $this->GetLocationPath($eventID),
            // The event's parent is what it is attached to (its default target).
            'AttachedTo' => ($parentID > 0) ? $this->DescribeObjectRef($parentID) : null,
            'LastRun' => (intval($event['LastRun'] ?? 0) > 0) ? date('c', intval($event['LastRun'])) : null,
            'NextRun' => (intval($event['NextRun'] ?? 0) > 0) ? date('c', intval($event['NextRun'])) : null
        ];

        switch ($kind) {
            case 'event.trigger':
                $triggerVariableID = intval($event['TriggerVariableID'] ?? 0);
                $out['Trigger'] = [
                    'Type' => $this->TriggerTypeName(intval($event['TriggerType'] ?? -1)),
                    'Variable' => ($triggerVariableID > 0 && IPS_ObjectExists($triggerVariableID))
                        ? $this->DescribeObjectRef($triggerVariableID)
                        : null,
                    'Value' => $event['TriggerValue'] ?? null
                ];
                break;

            case 'event.cyclic':
                $out['Cyclic'] = [
                    'Text' => $this->CyclicText($event),
                    'Note' => 'Text is best-effort; NextRun/LastRun above are authoritative.'
                ];
                break;

            case 'event.schedule':
                $out['WeeklySchedule'] = $this->DescribeSchedule($event);
                break;
        }

        if (!empty($event['EventConditions'])) {
            $out['Conditions'] = $this->DescribeConditions($event['EventConditions']);
        }

        // Complete raw definition — nothing is hidden. Prefer the resolved
        // fields above; never compute timestamps from raw values yourself.
        $out['Raw'] = $event;

        return $out;
    }

    private function TriggerTypeName(int $type): string
    {
        switch ($type) {
            case 0:
                return 'on-variable-update';
            case 1:
                return 'on-variable-change';
            case 2:
                return 'on-limit-exceed';
            case 3:
                return 'on-limit-drop';
            case 4:
                return 'on-specific-value';
            default:
                return 'unknown (' . $type . ')';
        }
    }

    private function CyclicText(array $event): string
    {
        $dateType = intval($event['CyclicDateType'] ?? -1);
        $dateValue = intval($event['CyclicDateValue'] ?? 0);
        $timeType = intval($event['CyclicTimeType'] ?? -1);
        $timeValue = intval($event['CyclicTimeValue'] ?? 0);

        switch ($dateType) {
            case 0:
                $date = 'no date rule';
                break;
            case 1:
                $date = 'once';
                break;
            case 2:
                $date = ($dateValue > 1) ? ('every ' . $dateValue . ' days') : 'daily';
                break;
            case 3:
                $date = (($dateValue > 1) ? ('every ' . $dateValue . ' weeks') : 'weekly')
                    . ' on ' . $this->WeekdayMaskText(intval($event['CyclicDateDay'] ?? 0));
                break;
            case 4:
                $date = ($dateValue > 1) ? ('every ' . $dateValue . ' months') : 'monthly';
                break;
            case 5:
                $date = ($dateValue > 1) ? ('every ' . $dateValue . ' years') : 'yearly';
                break;
            default:
                $date = 'date rule type ' . $dateType;
        }

        switch ($timeType) {
            case 0:
                $time = 'at a fixed time';
                break;
            case 1:
                $time = 'every ' . $timeValue . ' second(s)';
                break;
            case 2:
                $time = 'every ' . $timeValue . ' minute(s)';
                break;
            case 3:
                $time = 'every ' . $timeValue . ' hour(s)';
                break;
            default:
                $time = 'time rule type ' . $timeType;
        }

        return $date . ', ' . $time;
    }

    private function WeekdayMaskText(int $mask): string
    {
        $names = [1 => 'Mon', 2 => 'Tue', 4 => 'Wed', 8 => 'Thu', 16 => 'Fri', 32 => 'Sat', 64 => 'Sun'];
        $out = [];
        foreach ($names as $bit => $name) {
            if (($mask & $bit) !== 0) {
                $out[] = $name;
            }
        }
        return (count($out) > 0) ? implode(', ', $out) : '(none)';
    }

    private function DescribeSchedule(array $event): array
    {
        $actions = [];
        foreach (($event['ScheduleActions'] ?? []) as $action) {
            $actions[intval($action['ID'] ?? -1)] = strval($action['Name'] ?? '');
        }

        $groups = [];
        foreach (($event['ScheduleGroups'] ?? []) as $group) {
            $points = [];
            foreach (($group['Points'] ?? []) as $point) {
                $start = $point['Start'] ?? [];
                $actionID = intval($point['ActionID'] ?? -1);
                $points[] = [
                    'Time' => sprintf('%02d:%02d:%02d', intval($start['Hour'] ?? 0), intval($start['Minute'] ?? 0), intval($start['Second'] ?? 0)),
                    'Action' => (($actions[$actionID] ?? '') !== '') ? $actions[$actionID] : ('action #' . $actionID)
                ];
            }
            $groups[] = [
                'Days' => $this->WeekdayMaskText(intval($group['Days'] ?? 0)),
                'Points' => $points
            ];
        }

        return [
            'Actions' => array_values($actions),
            'Groups' => $groups
        ];
    }

    private function DescribeConditions(array $conditions): array
    {
        $out = [];
        foreach ($conditions as $condition) {
            if (!is_array($condition)) {
                continue;
            }
            $rules = [];
            foreach (($condition['VariableRules'] ?? []) as $rule) {
                $variableID = intval($rule['VariableID'] ?? 0);
                $rules[] = [
                    'Kind' => 'variable',
                    'Variable' => ($variableID > 0 && IPS_ObjectExists($variableID))
                        ? $this->DescribeObjectRef($variableID)
                        : ['ObjectID' => $variableID],
                    'Comparison' => $this->ComparisonSymbol(intval($rule['Comparison'] ?? -1)),
                    'Value' => $rule['Value'] ?? null
                ];
            }
            foreach (($condition['TimeRules'] ?? []) as $rule) {
                $value = $rule['Value'] ?? [];
                $rules[] = [
                    'Kind' => 'time',
                    'Comparison' => $this->ComparisonSymbol(intval($rule['Comparison'] ?? -1)),
                    'Value' => sprintf('%02d:%02d:%02d', intval($value['Hour'] ?? 0), intval($value['Minute'] ?? 0), intval($value['Second'] ?? 0))
                ];
            }
            foreach (($condition['DateRules'] ?? []) as $rule) {
                $value = $rule['Value'] ?? [];
                $rules[] = [
                    'Kind' => 'date',
                    'Comparison' => $this->ComparisonSymbol(intval($rule['Comparison'] ?? -1)),
                    'Value' => sprintf('%04d-%02d-%02d', intval($value['Year'] ?? 0), intval($value['Month'] ?? 0), intval($value['Day'] ?? 0))
                ];
            }
            foreach (($condition['DayOfTheWeekRules'] ?? []) as $rule) {
                $rules[] = [
                    'Kind' => 'day-of-week',
                    'Comparison' => $this->ComparisonSymbol(intval($rule['Comparison'] ?? -1)),
                    'ValueRaw' => $rule['Value'] ?? null,
                    'Note' => 'weekday index as stored by Symcon'
                ];
            }
            $out[] = [
                'Operation' => $this->ConditionOperationText(intval($condition['Operation'] ?? -1)),
                'Rules' => $rules
            ];
        }
        return $out;
    }

    private function ComparisonSymbol(int $comparison): string
    {
        switch ($comparison) {
            case 0:
                return '=';
            case 1:
                return '!=';
            case 2:
                return '>';
            case 3:
                return '>=';
            case 4:
                return '<';
            case 5:
                return '<=';
            default:
                return 'comparison ' . $comparison;
        }
    }

    private function ConditionOperationText(int $operation): string
    {
        switch ($operation) {
            case 0:
                return 'AND (all rules must match)';
            case 1:
                return 'OR (any rule may match)';
            case 2:
                return 'NAND';
            case 3:
                return 'NOR';
            default:
                return 'operation ' . $operation;
        }
    }

    private function DescribeScript(int $scriptID): array
    {
        $script = IPS_GetScript($scriptID);
        $content = IPS_GetScriptContent($scriptID);

        // Hard cap to protect the client context window (content beyond this
        // size cannot be reasoned about in one piece anyway).
        $cap = 100000;

        $out = [
            'ObjectID' => $scriptID,
            'Name' => IPS_GetName($scriptID),
            'Kind' => $this->AutomationKindOfScript($script),
            'ParentID' => IPS_GetParent($scriptID),
            'LocationPath' => $this->GetLocationPath($scriptID),
            'IsBroken' => boolval($script['ScriptIsBroken'] ?? false),
            'LastExecuted' => (intval($script['ScriptExecuted'] ?? 0) > 0) ? date('c', intval($script['ScriptExecuted'])) : null,
            'LastUpdated' => (intval($script['ScriptUpdated'] ?? 0) > 0) ? date('c', intval($script['ScriptUpdated'])) : null,
            'ContentSize' => strlen($content),
            // Objects this script/plan references (numeric ID tokens found in
            // the content, validated against the object tree; best-effort).
            'ReferencedObjects' => $this->ExtractObjectRefs($content)
        ];

        // Events attached to this script/plan (they run it).
        $attached = [];
        foreach (IPS_GetChildrenIDs($scriptID) as $childID) {
            if (IPS_GetObject($childID)['ObjectType'] !== 4) {
                continue;
            }
            $childEvent = IPS_GetEvent($childID);
            $attached[] = [
                'ObjectID' => $childID,
                'Name' => IPS_GetName($childID),
                'Kind' => $this->AutomationKindOfEvent($childEvent),
                'Active' => boolval($childEvent['EventActive'] ?? false)
            ];
        }
        $out['AttachedEvents'] = $attached;

        // Full source/definition. PHP scripts: PHP source. Flow plans
        // (Ablaufplan) and logic plans (Logikplan): their JSON definition.
        $out['ContentTruncated'] = strlen($content) > $cap;
        $out['Content'] = substr($content, 0, $cap);

        return $out;
    }

    /**
     * Best-effort extraction of referenced objects from script/plan content:
     * five-digit tokens that exist as objects in the tree.
     */
    private function ExtractObjectRefs(string $content): array
    {
        if (preg_match_all('/\b\d{5}\b/', $content, $matches) === false) {
            return [];
        }
        $ids = array_values(array_unique(array_map('intval', $matches[0])));
        $refs = [];
        foreach ($ids as $id) {
            if (!IPS_ObjectExists($id)) {
                continue;
            }
            $refs[] = $this->DescribeObjectRef($id);
            if (count($refs) >= 100) {
                break;
            }
        }
        return $refs;
    }

    /**
     * "Which automations touch object X?" over three sources with explicit
     * per-source completeness semantics (see tool description).
     */
    private function FindReferencesToObject(array $args): array
    {
        $objectID = intval($args['objectID'] ?? 0);
        if (!IPS_ObjectExists($objectID)) {
            throw new Exception('Object ' . $objectID . ' does not exist.');
        }
        $limit = max(1, min(intval($args['limit'] ?? 100), 500));
        $needle = '/\b' . $objectID . '\b/';

        // --- Events: structural check over every event => exact ---
        $eventMatches = [];
        $eventTotal = 0;
        foreach (IPS_GetEventList() as $eventID) {
            if ($eventID === $objectID) {
                continue;
            }
            $event = IPS_GetEvent($eventID);
            $roles = [];
            if (intval($event['TriggerVariableID'] ?? 0) === $objectID) {
                $roles[] = 'trigger';
            }
            foreach (($event['EventConditions'] ?? []) as $condition) {
                foreach (($condition['VariableRules'] ?? []) as $rule) {
                    if (intval($rule['VariableID'] ?? 0) === $objectID) {
                        $roles[] = 'condition';
                        break 2;
                    }
                }
            }
            if (IPS_GetParent($eventID) === $objectID) {
                $roles[] = 'attached-to-object';
            }
            if (count($roles) === 0 && preg_match($needle, json_encode($event) ?: '') === 1) {
                // e.g. the ID appears in action parameters of the definition
                $roles[] = 'referenced-in-definition';
            }
            if (count($roles) === 0) {
                continue;
            }
            $eventTotal++;
            if (count($eventMatches) < $limit) {
                $eventMatches[] = [
                    'ObjectID' => $eventID,
                    'Name' => IPS_GetName($eventID),
                    'Kind' => $this->AutomationKindOfEvent($event),
                    'Active' => boolval($event['EventActive'] ?? false),
                    'LocationPath' => $this->GetLocationPath($eventID),
                    'Roles' => $roles
                ];
            }
        }

        // --- Scripts/plans: literal ID token in content => best-effort ---
        $scriptMatches = [];
        $scriptTotal = 0;
        foreach (IPS_GetScriptList() as $scriptID) {
            if ($scriptID === $objectID) {
                continue;
            }
            $count = preg_match_all($needle, IPS_GetScriptContent($scriptID));
            if ($count === false || $count === 0) {
                continue;
            }
            $scriptTotal++;
            if (count($scriptMatches) < $limit) {
                $script = IPS_GetScript($scriptID);
                $scriptMatches[] = [
                    'ObjectID' => $scriptID,
                    'Name' => IPS_GetName($scriptID),
                    'Kind' => $this->AutomationKindOfScript($script),
                    'LocationPath' => $this->GetLocationPath($scriptID),
                    'MatchCount' => $count
                ];
            }
        }

        // --- Instances: literal ID token in configuration => best-effort ---
        $instanceMatches = [];
        $instanceTotal = 0;
        foreach (IPS_GetInstanceList() as $instanceID) {
            if ($instanceID === $objectID) {
                continue;
            }
            try {
                $config = IPS_GetConfiguration($instanceID);
            } catch (Throwable $e) {
                continue;
            }
            if (!is_string($config) || preg_match($needle, $config) !== 1) {
                continue;
            }
            $instanceTotal++;
            if (count($instanceMatches) < $limit) {
                $instance = IPS_GetInstance($instanceID);
                $instanceMatches[] = [
                    'ObjectID' => $instanceID,
                    'Name' => IPS_GetName($instanceID),
                    'ModuleName' => strval($instance['ModuleInfo']['ModuleName'] ?? ''),
                    'LocationPath' => $this->GetLocationPath($instanceID)
                ];
            }
        }

        return [
            'object' => $this->DescribeObjectRef($objectID),
            'events' => [
                'completeness' => 'exact',
                'matches' => $eventMatches,
                'totalMatches' => $eventTotal,
                'truncated' => $eventTotal > count($eventMatches)
            ],
            'scripts' => [
                'completeness' => 'best-effort',
                'matches' => $scriptMatches,
                'totalMatches' => $scriptTotal,
                'truncated' => $scriptTotal > count($scriptMatches)
            ],
            'instances' => [
                'completeness' => 'best-effort',
                'matches' => $instanceMatches,
                'totalMatches' => $instanceTotal,
                'truncated' => $instanceTotal > count($instanceMatches)
            ],
            'note' => 'events is exhaustive over stored event definitions. scripts/instances match the literal numeric ID; IDs computed at runtime cannot be detected — an empty result there does not prove absence.'
        ];
    }

    private function ClampLimit($limit): int
    {
        return max(1, min(intval($limit), 10000));
    }

    /**
     * Resolve the query time window from tool arguments.
     * Priority: "period" token > explicit from/to (ISO / relative / unix).
     * All resolution happens against the server's own clock and timezone,
     * so the LLM never has to compute timestamps.
     *
     * @return array{0:int,1:int} [from, to] as Unix seconds
     */
    private function ResolveTimeRange(array $args): array
    {
        $period = trim(strval($args['period'] ?? ''));
        if ($period !== '') {
            return $this->ResolvePeriod($period);
        }

        // Default: last 24h if nothing supplied.
        $from = array_key_exists('from', $args) && $args['from'] !== ''
            ? $this->ResolveInstant($args['from'])
            : (time() - 86400);
        $to = array_key_exists('to', $args) && $args['to'] !== ''
            ? $this->ResolveInstant($args['to'])
            : time();

        return [$from, $to];
    }

    /**
     * Resolve a single instant given as Unix seconds (number or numeric
     * string), an ISO 8601 datetime, or a relative expression ("-7 days").
     */
    private function ResolveInstant($value): int
    {
        if (is_int($value) || is_float($value)) {
            return intval($value);
        }
        $s = trim(strval($value));
        if ($s === '') {
            return time();
        }
        if (is_numeric($s)) {
            return intval($s);
        }
        $ts = strtotime($s);
        if ($ts === false) {
            throw new Exception('Could not parse time expression: "' . $s . '". Use ISO 8601 (2026-07-10T00:00:00), a relative expression (-7 days), or a Unix-second number.');
        }
        return $ts;
    }

    /**
     * Expand a relative period token into an exact [from, to) window,
     * DST-safe, computed in the server's local timezone.
     *
     * @return array{0:int,1:int}
     */
    private function ResolvePeriod(string $period): array
    {
        $tz = new DateTimeZone(date_default_timezone_get());
        $now = new DateTimeImmutable('now', $tz);
        $startOfToday = $now->setTime(0, 0, 0);

        switch ($period) {
            case 'today':
                $from = $startOfToday;
                $to = $now;
                break;

            case 'yesterday':
                $from = $startOfToday->modify('-1 day');
                $to = $startOfToday;
                break;

            case 'last-24h':
                $from = $now->modify('-24 hours');
                $to = $now;
                break;

            case 'this-week': // ISO week, Monday start
                $from = $startOfToday->modify('monday this week');
                $to = $now;
                break;

            case 'last-week':
                $from = $startOfToday->modify('monday last week');
                $to = $startOfToday->modify('monday this week');
                break;

            case 'last-7-days':
                $from = $startOfToday->modify('-7 days');
                $to = $now;
                break;

            case 'this-month':
                $from = $startOfToday->modify('first day of this month');
                $to = $now;
                break;

            case 'last-month':
                $from = $startOfToday->modify('first day of last month');
                $to = $startOfToday->modify('first day of this month');
                break;

            default:
                throw new Exception('Unknown period: "' . $period . '". Allowed: today, yesterday, last-24h, this-week, last-week, last-7-days, this-month, last-month.');
        }

        return [$from->getTimestamp(), $to->getTimestamp()];
    }

    private function ModeName(int $mode): string
    {
        switch ($mode) {
            case self::MODE_ADVISORY:
                return 'ADVISORY';
            case self::MODE_ACTIVE:
                return 'ACTIVE';
            default:
                return 'OFF';
        }
    }

    private function Schema(array $properties, array $required): array
    {
        return [
            'type' => 'object',
            'properties' => (object) $properties,
            'required' => $required,
            'additionalProperties' => false,
            '$schema' => 'http://json-schema.org/draft-07/schema#'
        ];
    }
}
