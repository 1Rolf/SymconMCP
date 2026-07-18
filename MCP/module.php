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
 *  - Capability facts (v1.2.0): every find-objects / get-children hit carries
 *    ObjectTypeName, and for variables VarType + Archived (links are resolved
 *    to their target). New find-objects filter "archived". Rationale: tool
 *    choice (get-value vs. get-logged-data vs. get-children) requires knowing
 *    what an object IS — without these facts the LLM can only probe and fail.
 */
class MCP extends IPSModuleStrict
{
    private const MODE_OFF = 0;
    private const MODE_ADVISORY = 1;
    private const MODE_ACTIVE = 2;

    private const ARCHIVE_GUID = '{43192F0B-135B-4CE7-A0A7-1475603F3060}';
    private const UTIL_GUID = '{B69010EA-96D5-46DF-B885-24821B8C8DBD}';

    // Human-readable names for IPS object types (index = ObjectType) and
    // variable types (index = VariableType). Numeric enums are weak signals
    // for an LLM; names make the object kind unambiguous in every hit.
    private const TYPE_NAMES = [
        0 => 'category',
        1 => 'instance',
        2 => 'variable',
        3 => 'script',
        4 => 'event',
        5 => 'media',
        6 => 'link'
    ];
    private const VARTYPE_NAMES = [
        0 => 'bool',
        1 => 'int',
        2 => 'float',
        3 => 'string'
    ];

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
- archived: true = only variables with history logging enabled (usable with
  get-logged-data / get-aggregated-data); false = only variables without
  logging. Setting this filter implies variables only. For history questions,
  set archived=true so unusable candidates never appear.
- lastUpdate: object with optional "from"/"to" Unix timestamps filtering by last update (scripts: last execution)
- limit: max results to return (default 50)

Each hit is compact: ObjectID, ObjectName, ObjectType, ObjectTypeName, ParentID
and LocationPath (the names of its ancestors, root first, e.g.
"Haus / EG / Wohnzimmer") — use LocationPath to determine which room an object
belongs to instead of traversing the tree. Variables additionally carry
VarType (bool/int/float/string) and Archived (whether history logging is
enabled). Links are resolved server-side: TargetID and TargetTypeName, plus
VarType/Archived if the target is a variable — use the TargetID with the
value/history tools. For full details on a specific hit, call get-object with
its ObjectID.

Choose the next tool from these facts instead of probing:
- get-value: only for variables (or link targets that are variables)
- get-logged-data / get-aggregated-data: only if Archived is true
- Archived=false means NO history exists — report the current value via
  get-value and say so honestly instead of retrying.
- categories/instances: inspect with get-children; scripts/events/media have
  no value at all.

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
                    'archived' => ['type' => 'boolean', 'description' => 'true: only variables with history logging enabled; false: only variables without logging. Implies variables only. Use archived=true for history questions.'],
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
                'description' => 'Get the object info for all children of the Symcon object with the given object ID. Use this to inspect ONE known object, not to search: for finding objects anywhere in the tree, use find-objects instead (it searches globally in a single call). Each child carries ObjectTypeName, and for variables VarType and Archived (links are resolved to TargetID/TargetTypeName) — pick the follow-up tool from these facts: get-value only for variables, history tools only if Archived is true. Required: You must provide a valid numeric objectID.',
                'inputSchema' => $this->Schema([
                    'objectID' => ['type' => 'number', 'description' => 'The numeric ID of the parent Symcon object. This is a required integer identifier. Must be a valid objectID from the system.']
                ], ['objectID']),
            ],
            [
                'name' => 'get-value',
                'title' => 'Get Value',
                'description' => 'Get the current value of the Symcon variable with the given object ID. Only variables have a value (ObjectTypeName "variable" in find-objects/get-children hits): calling this on categories, instances, scripts, events or media fails — inspect those with get-children instead. For links, call this with the resolved TargetID. Required: You must provide a valid numeric objectID. The function returns the formatted value by default. The formatted value is a human-readable string that includes relevant annotations such as units (e.g., \'25.5 °C\', \'78.2 °F\') or date/time formats (e.g., \'10:30 AM\'). This formatted output is intended for direct display or for programmatic parsing of units or other metadata. As such, the formatted value should usually preferred unless specific constellations, like debugging, require the raw value. In such a special scenario, the raw value can be requested by setting "raw" to true.',
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

PRECONDITION: only works for variables whose history logging is enabled —
check the Archived flag in find-objects/get-children hits, or search directly
with find-objects archived=true. If Archived is false, NO history exists:
report the current value via get-value and say so honestly instead of retrying.

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

PRECONDITION: only works for variables whose history logging is enabled —
check the Archived flag in find-objects/get-children hits, or search directly
with find-objects archived=true. If Archived is false, NO history exists:
report the current value via get-value and say so honestly instead of retrying.

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
                $archiveID = $this->GetArchiveIDSafe();
                $result = [];
                foreach (IPS_GetChildrenIDs(intval($args['objectID'])) as $childID) {
                    $info = IPS_GetObject($childID);
                    $result[] = array_merge(
                        $info,
                        $this->DescribeCapabilities($childID, intval($info['ObjectType']), $archiveID)
                    );
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
        $archiveID = $this->GetArchiveIDSafe();
        $totalMatches = 0;
        $result = [];

        foreach (json_decode(IPS_GetSnapshot(), true)['objects'] as $index => $object) {
            if (!str_contains(strtolower($object['name']), $searchName)) {
                continue;
            }
            if (!in_array($object['type'], $types)) {
                continue;
            }

            // "archived" filter: restrict to variables whose logging status
            // matches. Non-variables cannot satisfy it and are skipped, so a
            // history-question search never yields unusable candidates.
            if (array_key_exists('archived', $args)) {
                if ($object['type'] != 2) {
                    continue;
                }
                $variableID = intval(substr($index, 2));
                $isArchived = ($archiveID > 0) && AC_GetLoggingStatus($archiveID, $variableID);
                if ($isArchived !== boolval($args['archived'])) {
                    continue;
                }
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
                $result[] = array_merge(
                    [
                        'ObjectID' => $objectID,
                        'ObjectName' => $object['name'],
                        'ObjectType' => $object['type'],
                        'ParentID' => IPS_GetParent($objectID),
                        'LocationPath' => $this->GetLocationPath($objectID)
                    ],
                    $this->DescribeCapabilities($objectID, intval($object['type']), $archiveID)
                );
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
     * Archive Control instance ID, or 0 if none exists. Used by the hit
     * enrichment, which must never throw just because archiving is absent.
     */
    private function GetArchiveIDSafe(): int
    {
        $list = IPS_GetInstanceListByModuleID(self::ARCHIVE_GUID);
        return count($list) > 0 ? intval($list[0]) : 0;
    }

    /**
     * Capability facts for one object, appended to every find-objects /
     * get-children hit so the LLM can choose the follow-up tool from facts
     * instead of probing (design principle: make error classes impossible):
     *  - ObjectTypeName: human-readable object kind (numeric enums are weak
     *    signals for an LLM)
     *  - variables: VarType (bool/int/float/string) + Archived (whether
     *    history logging is enabled — precondition for get-logged-data /
     *    get-aggregated-data)
     *  - links: resolved server-side to TargetID/TargetTypeName (plus
     *    VarType/Archived if the target is a variable), so a link is never
     *    a dead end of the same error class.
     */
    private function DescribeCapabilities(int $objectID, int $objectType, int $archiveID): array
    {
        $out = [
            'ObjectTypeName' => self::TYPE_NAMES[$objectType] ?? 'unknown'
        ];

        if ($objectType === 2 /* variable */) {
            $var = IPS_GetVariable($objectID);
            $out['VarType'] = self::VARTYPE_NAMES[$var['VariableType']] ?? 'unknown';
            $out['Archived'] = ($archiveID > 0) && AC_GetLoggingStatus($archiveID, $objectID);
        } elseif ($objectType === 6 /* link */) {
            $targetID = intval(IPS_GetLink($objectID)['TargetID']);
            $out['TargetID'] = $targetID;
            if (IPS_ObjectExists($targetID)) {
                $targetType = intval(IPS_GetObject($targetID)['ObjectType']);
                $out['TargetTypeName'] = self::TYPE_NAMES[$targetType] ?? 'unknown';
                if ($targetType === 2) {
                    $var = IPS_GetVariable($targetID);
                    $out['VarType'] = self::VARTYPE_NAMES[$var['VariableType']] ?? 'unknown';
                    $out['Archived'] = ($archiveID > 0) && AC_GetLoggingStatus($archiveID, $targetID);
                }
            } else {
                $out['TargetTypeName'] = 'missing';
            }
        }

        return $out;
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
