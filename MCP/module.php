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
        $request = json_decode(file_get_contents('php://input'), true);
        $result = [];

        $this->SendDebug('MCP Input', json_encode($request), 0);
        $this->SendDebug('MCP Method', strval($request['method'] ?? ''), 0);

        switch ($request['method'] ?? '') {
            case 'tools/list':
                $result = [
                    'tools' => $this->BuildToolList()
                ];
                break;

            case 'tools/call':
                $content = $this->HandleToolCall(
                    strval($request['params']['name'] ?? ''),
                    $request['params']['arguments'] ?? []
                );
                $result = [
                    'content' => [
                        [
                            'type' => 'text',
                            'text' => json_encode($content)
                        ]
                    ],
                    'structuredContent' => $content,
                ];
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
                        'version' => '1.1.0'
                    ]
                ];
                break;

            case 'notifications/initialized':
                http_response_code(202);
                return;

            default:
                break;
        }

        header('Content-Type: application/json');

        $this->SendDebug('MCP Result', json_encode($result), 0);

        echo json_encode([
            'result' => $result,
            'jsonrpc' => '2.0',
            'id' => $request['id'] ?? null
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
                'outputSchema' => $this->Schema(['info' => ['type' => 'object']], ['info'])
            ],
            [
                'name' => 'get-name',
                'title' => 'Get Object Name',
                'description' => 'Get the name of the Symcon object with the given object ID.',
                'inputSchema' => $this->Schema(['objectID' => ['type' => 'number']], ['objectID']),
                'outputSchema' => $this->Schema(['name' => ['type' => 'string']], ['name'])
            ],
            [
                'name' => 'find-objects',
                'title' => 'Find Symcon Objects by using different kinds of filters',
                'description' => <<<DESC
Find Symcon objects based on various criteria.

The supported filters are:
- type: an array of possible object types (0: category, 1: instance, 2: variable, 3: script, 4: event, 5: media, 6: link)
- name: a string that will be searched for in the object names (case insensitive, partial match)
- usage: filters by the usage type. Currently the only supported value is "temperature" which lists all objects for temperatures
- lastUpdate: an object with optional "from" and "to" Unix timestamps (in seconds) to filter objects based on their last update time, for scripts an update refers to an execution

If multiple filters are provided, only objects matching all criteria will be returned.
For each object, the full object info as returned by IPS_GetObject will be included in the output, containing ObjectID, ObjectName, ObjectType and more.
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
                    ]
                ], []),
                'outputSchema' => $this->Schema([
                    'objects' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'ObjectID' => ['type' => 'number'],
                                'ObjectType' => ['type' => 'number'],
                                'ObjectName' => ['type' => 'string'],
                                'ObjectInfo' => ['type' => 'string'],
                                'ParentID' => ['type' => 'number'],
                                'ObjectPosition' => ['type' => 'number'],
                                'ObjectIsHidden' => ['type' => 'boolean'],
                                'ObjectIsReadOnly' => ['type' => 'boolean'],
                            ],
                            'required' => ['ObjectID', 'ObjectType', 'ObjectName', 'ObjectInfo', 'ParentID', 'ObjectPosition', 'ObjectIsHidden', 'ObjectIsReadOnly'],
                            'additionalProperties' => true,
                        ]
                    ]
                ], ['objects'])
            ],
            [
                'name' => 'get-children',
                'title' => 'Get Children',
                'description' => 'Get the object info for all children of the Symcon object with the given object ID. For each child, the full object info as returned by IPS_GetObject will be included in the output. Required: You must provide a valid numeric objectID.',
                'inputSchema' => $this->Schema([
                    'objectID' => ['type' => 'number', 'description' => 'The numeric ID of the parent Symcon object. This is a required integer identifier. Must be a valid objectID from the system.']
                ], ['objectID']),
                'outputSchema' => $this->Schema([
                    'children' => ['type' => 'array', 'items' => ['type' => 'object']]
                ], ['children'])
            ],
            [
                'name' => 'get-value',
                'title' => 'Get Value',
                'description' => 'Get the current value of the Symcon variable with the given object ID. Required: You must provide a valid numeric objectID. The function returns the formatted value by default. The formatted value is a human-readable string that includes relevant annotations such as units (e.g., \'25.5 °C\', \'78.2 °F\') or date/time formats (e.g., \'10:30 AM\'). This formatted output is intended for direct display or for programmatic parsing of units or other metadata. As such, the formatted value should usually preferred unless specific constellations, like debugging, require the raw value. In such a special scenario, the raw value can be requested by setting "raw" to true.',
                'inputSchema' => $this->Schema([
                    'objectID' => ['type' => 'number', 'description' => 'The numeric ID of the Symcon variable. This is a required integer identifier. Must be a valid objectID from the system.'],
                    'raw' => ['type' => 'boolean', 'description' => 'If true, the raw value is returned, otherwise the formatted value, including annotations like units or date/time formatting based on the variable presentation.']
                ], ['objectID']),
                'outputSchema' => $this->Schema([
                    'value' => ['type' => 'any', 'description' => 'The current value of the Symcon variable.']
                ], [])
            ],
            [
                'name' => 'current-time',
                'title' => 'Get Current Time',
                'description' => 'Returns the current Unix timestamp in seconds since January 1, 1970 UTC.',
                'inputSchema' => $this->Schema([], []),
                'outputSchema' => $this->Schema([
                    'timestamp' => ['type' => 'number', 'description' => 'Current Unix timestamp in seconds'],
                    'formatted' => ['type' => 'string', 'description' => 'Human-readable date and time in ISO 8601 format']
                ], ['timestamp', 'formatted'])
            ],
            [
                'name' => 'get-logged-data',
                'title' => 'Get Logged Variable Data',
                'description' => <<<DESC
Get historical raw logged data for a Symcon variable within a specified time range.
Returns logged values between the from and to timestamps, newest first, capped by "limit"
(default 1000, max 10000). For long time ranges prefer get-aggregated-data to keep the
response small, and use get-logged-data only for narrow windows you want to inspect in detail.
Each data entry includes the original Unix timestamp and a formatted ISO 8601 timestamp.
DESC,
                'inputSchema' => $this->Schema([
                    'variableID' => ['type' => 'number', 'description' => 'The numeric ID of the Symcon variable to get logged data from'],
                    'from' => ['type' => 'number', 'description' => 'Start Unix timestamp in seconds'],
                    'to' => ['type' => 'number', 'description' => 'End Unix timestamp in seconds'],
                    'limit' => ['type' => 'number', 'description' => 'Maximum number of records to return (default 1000, max 10000)']
                ], ['variableID']),
                'outputSchema' => $this->Schema([
                    'data' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'TimeStamp' => ['type' => 'number', 'description' => 'Unix timestamp when the value was logged'],
                                'Value' => ['type' => 'any', 'description' => 'The logged value (can be string, number, boolean)'],
                                'Duration' => ['type' => 'number', 'description' => 'The duration for which the value was logged (in seconds)'],
                                'FormattedTime' => ['type' => 'string', 'description' => 'Human-readable timestamp in ISO 8601 format']
                            ],
                            'required' => ['TimeStamp', 'Value', 'Duration', 'FormattedTime']
                        ]
                    ]
                ], ['data'])
            ],
            [
                'name' => 'get-aggregated-data',
                'title' => 'Get Aggregated Variable Data',
                'description' => <<<DESC
Get aggregated historical data (min/max/avg per bucket) for a logged Symcon variable.
This is the preferred tool for long time ranges (weeks/months) because it keeps the
response small. Aggregation levels: 0 = hourly, 1 = daily, 2 = weekly, 3 = monthly,
4 = yearly (1-minute/5-minute levels may be available depending on the Symcon version).
Returns buckets newest first, capped by "limit" (default 1000).
DESC,
                'inputSchema' => $this->Schema([
                    'variableID' => ['type' => 'number', 'description' => 'The numeric ID of the logged Symcon variable'],
                    'level' => ['type' => 'number', 'description' => 'Aggregation level: 0=hourly, 1=daily, 2=weekly, 3=monthly, 4=yearly'],
                    'from' => ['type' => 'number', 'description' => 'Start Unix timestamp in seconds'],
                    'to' => ['type' => 'number', 'description' => 'End Unix timestamp in seconds'],
                    'limit' => ['type' => 'number', 'description' => 'Maximum number of buckets to return (default 1000, max 10000)']
                ], ['variableID', 'level']),
                'outputSchema' => $this->Schema([
                    'data' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'TimeStamp' => ['type' => 'number', 'description' => 'Unix timestamp of the bucket start'],
                                'Duration' => ['type' => 'number', 'description' => 'Bucket duration in seconds'],
                                'Min' => ['type' => 'any'],
                                'Max' => ['type' => 'any'],
                                'Avg' => ['type' => 'any'],
                                'FormattedTime' => ['type' => 'string', 'description' => 'Human-readable bucket start in ISO 8601 format']
                            ],
                            'required' => ['TimeStamp', 'Duration', 'FormattedTime']
                        ]
                    ]
                ], ['data'])
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
                'outputSchema' => $this->Schema([
                    'data' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'TimeStamp' => ['type' => 'number', 'description' => 'Unix timestamp when the value was logged'],
                                'SenderID' => ['type' => 'number', 'description' => 'The ID of the sender of the message'],
                                'Sender' => ['type' => 'string', 'description' => 'The name of the sender of the message'],
                                'Message' => ['type' => 'string', 'description' => 'The log message content'],
                                'FormattedTime' => ['type' => 'string', 'description' => 'Human-readable timestamp in ISO 8601 format'],
                                'Type' => ['type' => 'number', 'description' => 'The type of the message'],
                            ],
                            'required' => ['TimeStamp', 'SenderID', 'Sender', 'FormattedTime']
                        ]
                    ]
                ], ['data'])
            ],
            [
                'name' => 'get-write-policy',
                'title' => 'Get Write Policy for a Variable',
                'description' => 'Check whether the given variable could be switched by the LLM under the current write mode and metadata policy. Read-only: performs no action. Use this before attempting switch-boolean to avoid futile calls.',
                'inputSchema' => $this->Schema([
                    'variableID' => ['type' => 'number', 'description' => 'The numeric ID of the Symcon variable to check']
                ], ['variableID']),
                'outputSchema' => $this->Schema([
                    'mode' => ['type' => 'string', 'description' => 'Current write mode: OFF, ADVISORY or ACTIVE'],
                    'allowed' => ['type' => 'boolean', 'description' => 'Whether the policy would allow switching this variable'],
                    'reason' => ['type' => 'string', 'description' => 'Explanation of the policy decision']
                ], ['mode', 'allowed', 'reason'])
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
                'outputSchema' => $this->Schema([
                    'success' => ['type' => 'boolean'],
                    'advisory' => ['type' => 'boolean', 'description' => 'True if the call was recorded in advisory mode instead of being executed'],
                    'message' => ['type' => 'string'],
                    'error' => ['type' => 'string']
                ], ['success'])
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
                $loggedData = AC_GetLoggedValues(
                    $this->GetArchiveID(),
                    intval($args['variableID']),
                    intval($args['from'] ?? 0),
                    intval($args['to'] ?? 0),
                    $limit
                );
                foreach ($loggedData as &$entry) {
                    $entry['FormattedTime'] = date('c', $entry['TimeStamp']);
                }
                return [
                    'data' => $loggedData
                ];

            case 'get-aggregated-data':
                $limit = $this->ClampLimit($args['limit'] ?? 1000);
                $aggregated = AC_GetAggregatedValues(
                    $this->GetArchiveID(),
                    intval($args['variableID']),
                    intval($args['level']),
                    intval($args['from'] ?? 0),
                    intval($args['to'] ?? 0),
                    $limit
                );
                foreach ($aggregated as &$entry) {
                    $entry['FormattedTime'] = date('c', $entry['TimeStamp']);
                }
                return [
                    'data' => $aggregated
                ];

            case 'get-status-log':
                return $this->GetStatusLog($args);

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
            $result[] = IPS_GetObject(intval(substr($index, 2)));
        }
        return [
            'objects' => $result
        ];
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

    private function ClampLimit($limit): int
    {
        return max(1, min(intval($limit), 10000));
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
