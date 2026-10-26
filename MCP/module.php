<?php

declare(strict_types=1);

// CLASS MCP
class MCP extends IPSModuleStrict
{
    /**
     * In contrast to Construct, this function is called only once when creating the instance and starting IP-Symcon.
     * Therefore, status variables and module properties which the module requires permanently should be created here.
     */
    public function Create() : void
    {
        //Never delete this line!
        parent::Create();

        $this->RegisterHook('mcp');
    }

    /**
     * This function is called when deleting the instance during operation and when updating via "Module Control".
     * The function is not called when exiting IP-Symcon.
     */
    public function Destroy() : void
    {
        parent::Destroy();
    }

    /**
     * Is executed when "Apply" is pressed on the configuration page and immediately after the instance has been created.
     */
    public function ApplyChanges() : void
    {
        parent::ApplyChanges();
    }

    /**
    * This function will be called by the hook control. Visibility should be protected!
    */
    protected function ProcessHookData(): void {
        // Anything printed while handling the request (e.g. PHP deprecation notices) would precede our headers
        // and turn the response into text/html. Collect it, log it and send only our own body
        ob_start();
        $response = null;
        try {
            $response = $this->HandleRequest();
        } catch (Throwable $e) {
            // The request id is not available here and "id": null is not allowed, so answer without a body
            http_response_code(500);
            $this->LogMessage('MCP: Internal error: ' . $e->getMessage() . ' (' . $e->getFile() . ':' . $e->getLine() . ')', KL_ERROR);
        } finally {
            $output = ob_get_clean();
        }

        if (is_string($output) && $output !== '') {
            $this->SendDebug('MCP Discarded Output', $output, 0);
            $this->LogMessage('MCP: Discarded unexpected output (' . strlen($output) . ' bytes): ' . substr(trim(preg_replace('/\s+/', ' ', strip_tags($output))), 0, 500), KL_WARNING);
        }

        if ($response !== null) {
            header('Content-Type: application/json');
            echo $response;
        }
    }

    private function HandleRequest(): ?string {
        // Only POST carries JSON-RPC messages. GET (SSE stream) and DELETE (session termination) are not offered
        if (($_SERVER['REQUEST_METHOD'] ?? 'POST') !== 'POST') {
            http_response_code(405);
            header('Allow: POST');
            return null;
        }

        $request = json_decode(file_get_contents('php://input'), true);
        $result = [];
        $error = null;

        $this->SendDebug('MCP Input', json_encode($request), 0);

        // Invalid JSON or a batch (not supported since protocol version 2025-06-18)
        if (!is_array($request) || array_is_list($request)) {
            http_response_code(400);
            return null;
        }

        // Notifications and responses are accepted without a body
        if (!array_key_exists('id', $request) || (!array_key_exists('method', $request) && (array_key_exists('result', $request) || array_key_exists('error', $request)))) {
            http_response_code(202);
            return null;
        }

        // A request id must be a string or a number that can be sent back unchanged (not null, a boolean, an object or e.g. 1e400)
        if (!is_string($request['id']) && !is_int($request['id']) && !(is_float($request['id']) && is_finite($request['id']))) {
            http_response_code(400);
            return null;
        }

        $method = $request['method'] ?? null;
        if (!is_string($method)) {
            $error = [
                'code' => -32600,
                'message' => 'Invalid Request'
            ];
            $method = '';
        }

        $this->SendDebug('MCP Method', $method, 0);

        switch ($method) {
            case 'tools/list':
            case 'tools/call':
                $result = [
                    'tools' => [
                        [
                            'name' => 'switch-boolean',
                            'title' => 'Switch Tool',
                            'description' => 'Switch a Symcon variable to a specified boolean value',
                            'inputSchema' => [
                                'type' => 'object',
                                'properties' => [
                                    'variableID' => [
                                        'type' => 'integer'
                                    ],
                                    'value' => [
                                        'type' => 'boolean'
                                    ]
                                ],
                                'required' => [
                                    'variableID',
                                    'value'
                                ],
                                'additionalProperties' => false,
                                '$schema' => 'http://json-schema.org/draft-07/schema#'
                            ],
                            'outputSchema' => [
                                'type' => 'object',
                                'properties' => [
                                    'success' => [
                                        'type' => 'boolean'
                                    ],
                                    'error' => [
                                        'type' => 'string'
                                    ]
                                ],
                                'required' => [
                                    'success'
                                ],
                                'additionalProperties' => false,
                                '$schema' => 'http://json-schema.org/draft-07/schema#'
                            ]
                        ],
                        [
                            'name' => 'get-object',
                            'title' => 'Get Object Info',
                            'description' => 'Get the object info for the Symcon object with the given object ID. The output matches that of IPS_GetObject.',
                            'inputSchema' => [
                                'type' => 'object',
                                'properties' => [
                                    'objectID' => [
                                        'type' => 'integer'
                                    ],
                                ],
                                'required' => [
                                    'objectID'
                                ],
                                'additionalProperties' => false,
                                '$schema' => 'http://json-schema.org/draft-07/schema#'
                            ],
                            'outputSchema' => [
                                'type' => 'object',
                                'properties' => [
                                    'info' => [
                                        'type' => 'object'
                                    ]
                                ],
                                'required' => [
                                    'info'
                                ],
                                'additionalProperties' => false,
                                '$schema' => 'http://json-schema.org/draft-07/schema#'
                            ]
                        ],
                        [
                            'name' => 'get-name',
                            'title' => 'Get Object Name',
                            'description' => 'Get the name of the Symcon object with the given object ID.',
                            'inputSchema' => [
                                'type' => 'object',
                                'properties' => [
                                    'objectID' => [
                                        'type' => 'integer'
                                    ],
                                ],
                                'required' => [
                                    'objectID'
                                ],
                                'additionalProperties' => false,
                                '$schema' => 'http://json-schema.org/draft-07/schema#'
                            ],
                            'outputSchema' => [
                                'type' => 'object',
                                'properties' => [
                                    'name' => [
                                        'type' => 'string'
                                    ]
                                ],
                                'required' => [
                                    'name'
                                ],
                                'additionalProperties' => false,
                                '$schema' => 'http://json-schema.org/draft-07/schema#'
                            ]
                        ],
                        [
                            'name' => 'get-snapshot',
                            'title' => 'Get Symcon Snapshot',
                            'description' => 'Get the snapshot of the Symcon system with data for all included objects.',
                            'inputSchema' => [
                                'type' => 'object',
                                'additionalProperties' => false,
                                '$schema' => 'http://json-schema.org/draft-07/schema#'
                            ],
                            'outputSchema' => [
                                'type' => 'object',
                                'properties' => [
                                    'snapshot' => [
                                        'type' => 'string',
                                        'description' => 'JSON-encoded snapshot'
                                    ]
                                ],
                                'required' => [
                                    'snapshot'
                                ],
                                'additionalProperties' => false,
                                '$schema' => 'http://json-schema.org/draft-07/schema#'
                            ]
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
                            'inputSchema' => [
                                'type' => 'object',
                                'properties' => [
                                    'types' => [
                                        'type' => 'array',
                                        'items' => [
                                            'type' => 'number'
                                        ]
                                    ],
                                    'name' => [
                                        'type' => 'string'
                                    ],
                                    'usage' => [
                                        'type' => 'string'
                                    ],
                                    'lastUpdate' => [
                                        'type' => 'object',
                                        'properties' => [
                                            'from' => [
                                                'type' => 'number',
                                                'description' => 'Unix timestamp in seconds'
                                            ],
                                            'to' => [
                                                'type' => 'number',
                                                'description' => 'Unix timestamp in seconds'
                                            ]
                                        ],
                                        'required' => [],
                                        'additionalProperties' => false
                                    ]
                                ],
                                'required' => [],
                                'additionalProperties' => false,
                                '$schema' => 'http://json-schema.org/draft-07/schema#'
                            ],
                            'outputSchema' => [
                                'type' => 'object',
                                'properties' => [
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
                                ],
                                'required' => [
                                    'objects'
                                ],
                                'additionalProperties' => false,
                                '$schema' => 'http://json-schema.org/draft-07/schema#'
                            ]
                        ],
                        [
                            'name' => 'get-children',
                            'title' => 'Get Children',
                            'description' => 'Get the object info for all children of the Symcon object with the given object ID. For each child, the full object info as returned by IPS_GetObject will be included in the output. Required: You must provide a valid numeric objectID.',
                            'inputSchema' => [
                                'type' => 'object',
                                'properties' => [
                                    'objectID' => [
                                        'type' => 'integer',
                                        'description' => 'The numeric ID of the parent Symcon object. This is a required integer identifier. Must be a valid objectID from the system.'
                                    ],
                                ],
                                'required' => [
                                    'objectID'
                                ],
                                'additionalProperties' => false,
                                '$schema' => 'http://json-schema.org/draft-07/schema#'
                            ],
                            'outputSchema' => [
                                'type' => 'object',
                                'properties' => [
                                    'children' => [
                                        'type' => 'array',
                                        'items' => [
                                            'type' => 'object'
                                        ]
                                    ]
                                ],
                                'required' => [
                                    'children'
                                ],
                                'additionalProperties' => false,
                                '$schema' => 'http://json-schema.org/draft-07/schema#'
                            ]
                        ],
                        [
                            'name' => 'get-value',
                            'title' => 'Get Value',
                            'description' => 'Get the current value of the Symcon variable with the given object ID. Required: You must provide a valid numeric objectID. The function returns the formatted value by default. The formatted value is a human-readable string that includes relevant annotations such as units (e.g., \'25.5 °C\', \'78.2 °F\') or date/time formats (e.g., \'10:30 AM\'). This formatted output is intended for direct display or for programmatic parsing of units or other metadata. As such, the formatted value should usually preferred unless specific constellations, like debugging, require the raw value. In such a special scenario, the raw value can be requested by setting "raw" to true.',
                            'inputSchema' => [
                                'type' => 'object',
                                'properties' => [
                                    'objectID' => [
                                        'type' => 'integer',
                                        'description' => 'The numeric ID of the parent Symcon object. This is a required integer identifier. Must be a valid objectID from the system.'
                                    ],
                                    'raw' => [
                                        'type' => 'boolean',
                                        'description' => 'If true, the raw value is returned, otherwise the formatted value, including annotations like units or date/time formatting based on the variable presentation.'
                                    ]
                                ],
                                'required' => [
                                    'objectID'
                                ],
                                'additionalProperties' => false,
                                '$schema' => 'http://json-schema.org/draft-07/schema#'
                            ],
                            'outputSchema' => [
                                'type' => 'object',
                                'properties' => [
                                    'value' => [
                                        'type' => ['boolean', 'number', 'string'],
                                        'description' => 'The current value of the Symcon variable.'
                                    ]
                                ],
                                'required' => [
                                ],
                                'additionalProperties' => false,
                                '$schema' => 'http://json-schema.org/draft-07/schema#'
                            ]
                        ],
                        [
                            'name' => 'rename-object',
                            'title' => 'Rename Object',
                            'description' => 'Rename a Symcon object to the specified new name. Required: You must provide both a valid numeric objectID and a newName string.',
                            'inputSchema' => [
                                'type' => 'object',
                                'properties' => [
                                    'objectID' => [
                                        'type' => 'integer',
                                        'description' => 'The numeric ID of the Symcon object to rename. This is a required integer identifier. Must be a valid objectID from the system.'
                                    ],
                                    'newName' => [
                                        'type' => 'string',
                                        'description' => 'The new name for the object. This is a required string parameter.'
                                    ]
                                ],
                                'required' => [
                                    'objectID',
                                    'newName'
                                ],
                                'additionalProperties' => false,
                                '$schema' => 'http://json-schema.org/draft-07/schema#'
                            ],
                            'outputSchema' => [
                                'type' => 'object',
                                'properties' => [
                                    'success' => [
                                        'type' => 'boolean'
                                    ]
                                ],
                                'required' => [
                                    'success'
                                ],
                                'additionalProperties' => false,
                                '$schema' => 'http://json-schema.org/draft-07/schema#'
                            ]
                        ],
                        [
                            'name' => 'current-time',
                            'title' => 'Get Current Time',
                            'description' => 'Returns the current Unix timestamp in seconds since January 1, 1970 UTC.',
                            'inputSchema' => [
                                'type' => 'object',
                                'properties' => new stdClass(),
                                'required' => [
                                ],
                                'additionalProperties' => false,
                                '$schema' => 'http://json-schema.org/draft-07/schema#'
                            ],
                            'outputSchema' => [
                                'type' => 'object',
                                'properties' => [
                                    'timestamp' => [
                                        'type' => 'number',
                                        'description' => 'Current Unix timestamp in seconds'
                                    ],
                                    'formatted' => [
                                        'type' => 'string',
                                        'description' => 'Human-readable date and time in ISO 8601 format'
                                    ]
                                ],
                                'required' => [
                                    'timestamp',
                                    'formatted'
                                ],
                                'additionalProperties' => false,
                                '$schema' => 'http://json-schema.org/draft-07/schema#'
                            ]
                        ],
                        [
                            'name' => 'get-logged-data',
                            'title' => 'Get Logged Variable Data',
                            'description' => <<<DESC
Get historical logged data for a Symcon variable within a specified time range.
Returns all logged values between the from and to timestamps.
Each data entry includes the original Unix timestamp and a formatted ISO 8601 timestamp for better readability.
DESC,
                            'inputSchema' => [
                                'type' => 'object',
                                'properties' => [
                                    'variableID' => [
                                        'type' => 'integer',
                                        'description' => 'The numeric ID of the Symcon variable to get logged data from'
                                    ],
                                    'from' => [
                                        'type' => 'number',
                                        'description' => 'Start Unix timestamp in seconds'
                                    ],
                                    'to' => [
                                        'type' => 'number',
                                        'description' => 'End Unix timestamp in seconds'
                                    ]
                                ],
                                'required' => [
                                    'variableID'
                                ],
                                'additionalProperties' => false,
                                '$schema' => 'http://json-schema.org/draft-07/schema#'
                            ],
                            'outputSchema' => [
                                'type' => 'object',
                                'properties' => [
                                    'data' => [
                                        'type' => 'array',
                                        'items' => [
                                            'type' => 'object',
                                            'properties' => [
                                                'TimeStamp' => [
                                                    'type' => 'number',
                                                    'description' => 'Unix timestamp when the value was logged'
                                                ],
                                                'Value' => [
                                                    'type' => ['boolean', 'number', 'string'],
                                                    'description' => 'The logged value (can be string, number, boolean)'
                                                ],
                                                'Duration' => [
                                                    'type' => 'number',
                                                    'description' => 'The duration for which the value was logged (in seconds)'
                                                ],
                                                'FormattedTime' => [
                                                    'type' => 'string',
                                                    'description' => 'Human-readable timestamp in ISO 8601 format'
                                                ]
                                            ],
                                            'required' => ['TimeStamp', 'Value', 'Duration', 'FormattedTime']
                                        ]
                                    ]
                                ],
                                'required' => [
                                    'data'
                                ],
                                'additionalProperties' => false,
                                '$schema' => 'http://json-schema.org/draft-07/schema#'
                            ]
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
                            'inputSchema' => [
                                'type' => 'object',
                                'properties' => [
                                    'types' => [
                                        'type' => 'array',
                                        'items' => [
                                            'type' => 'number'
                                        ],
                                        'description' => 'Array of message types to filter by'
                                    ]
                                ],
                                'required' => [
                                ],
                                'additionalProperties' => false,
                                '$schema' => 'http://json-schema.org/draft-07/schema#'
                            ],
                            'outputSchema' => [
                                'type' => 'object',
                                'properties' => [
                                    'data' => [
                                        'type' => 'array',
                                        'items' => [
                                            'type' => 'object',
                                            'properties' => [
                                                'TimeStamp' => [
                                                    'type' => 'number',
                                                    'description' => 'Unix timestamp when the value was logged'
                                                ],
                                                'SenderID' => [
                                                    'type' => 'number',
                                                    'description' => 'The ID of the sender of the message'
                                                ],
                                                'Sender' => [
                                                    'type' => 'string',
                                                    'description' => 'The name of the sender of the message'
                                                ],
                                                'Message' => [
                                                    'type' => 'string',
                                                    'description' => 'The log message content'
                                                ],
                                                'FormattedTime' => [
                                                    'type' => 'string',
                                                    'description' => 'Human-readable timestamp in ISO 8601 format'
                                                ],
                                                'Type' => [
                                                    'type' => 'number',
                                                    'description' => 'The type of the message'
                                                ],
                                            ],
                                            'required' => ['TimeStamp', 'SenderID', 'Sender', 'FormattedTime']
                                        ]
                                    ]
                                ],
                                'required' => [
                                    'data'
                                ],
                                'additionalProperties' => false,
                                '$schema' => 'http://json-schema.org/draft-07/schema#'
                            ]
                        ]
                    ]
                ];
                if ($method === 'tools/list') {
                    break;
                }

                // tools/call: the tool list above is the reference for the accepted arguments
                $inputSchemas = array_column($result['tools'], 'inputSchema', 'name');
                $result = [];
                $toolName = $request['params']['name'] ?? null;
                if (!is_string($toolName)) {
                    $error = [
                        'code' => -32602,
                        'message' => 'Invalid params: missing tool name'
                    ];
                    break;
                }

                // The CallToolRequest schema requires an object. A missing value or null counts as no arguments
                $arguments = $request['params']['arguments'] ?? [];
                if (!is_array($arguments) || ($arguments !== [] && array_is_list($arguments))) {
                    $error = [
                        'code' => -32602,
                        'message' => 'Invalid params: arguments must be an object'
                    ];
                    break;
                }

                // Symcon functions report failures as PHP warnings. Turn them into exceptions so that they end up in the tool result
                set_error_handler(function (int $errno, string $errstr): bool {
                    if (!(error_reporting() & $errno) || in_array($errno, [E_DEPRECATED, E_USER_DEPRECATED])) {
                        return false;
                    }
                    throw new ErrorException($errstr, 0, $errno);
                });

                $content = [];
                try {
                    // A misspelled or outdated parameter (e.g. a filter) would otherwise be ignored silently
                    if (isset($inputSchemas[$toolName])) {
                        $this->RequireKnownArguments($arguments, $inputSchemas[$toolName], '');
                    }
                    switch ($toolName) {
                        case 'get-name':
                            $content = [
                                'name' => IPS_GetName($this->RequireObjectID($request['params']['arguments'] ?? [], 'objectID'))
                            ];
                            break;

                        case 'get-object':
                            $content = [
                                'info' => IPS_GetObject($this->RequireObjectID($request['params']['arguments'] ?? [], 'objectID'))
                            ];
                            break;

                        case 'get-snapshot':
    		                ini_set('ips.output_buffer', 20*1024*1024);
                            $content = [
                                'snapshot' => IPS_GetSnapshot()
                            ];
                            break;

                        case 'find-objects':
                            $searchName = strtolower($request['params']['arguments']['name'] ?? '');
                            $types = $request['params']['arguments']['types'] ?? [0,1,2,3,4,5,6];
                            $usage = $request['params']['arguments']['usage'] ?? '';
                            $result = [];

                            foreach(json_decode(IPS_GetSnapshot(), true)['objects'] as $index => $object) {
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

                                if (isset($request['params']['arguments']['lastUpdate'])) {
                                    $from = $request['params']['arguments']['lastUpdate']['from'] ?? null;
                                    $to = $request['params']['arguments']['lastUpdate']['to'] ?? null;
                                    $objectID = intval(substr($index, 2));
                                    $lastUpdate;
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
                                    if (isset($from)) {
                                        if ($lastUpdate < $from) {
                                            continue;
                                        }
                                    }
                                    if (isset($to)) {
                                        if ($lastUpdate > $to) {
                                            continue;
                                        }
                                    }
                                }
                                $result[] = IPS_GetObject(intval(substr($index, 2)));
                            }
                            $content = [
                                'objects' => $result
                            ];
                            break;

                        case 'get-children':
                            $result = [];

                            foreach (IPS_GetChildrenIDs($this->RequireObjectID($request['params']['arguments'] ?? [], 'objectID')) as $childID) {
                                $result[] = IPS_GetObject($childID);
                            }
                            $content = [
                                'children' => $result
                            ];
                            break;

                        case 'get-value':
                            $objectID = $this->RequireObjectID($request['params']['arguments'] ?? [], 'objectID');
                            $result = ($request['params']['arguments']['raw'] ?? false) ? GetValue($objectID) : GetValueFormatted($objectID);

                            $content = [
                                'value' => $result
                            ];
                            break;

                        case 'rename-object':
                            IPS_SetName($this->RequireObjectID($request['params']['arguments'] ?? [], 'objectID'), $request['params']['arguments']['newName']);
                            $content = [
                                'success' => true
                            ];
                            break;

                        case 'switch-boolean':
                            $this->SendDebug('Switch Variable', json_encode($request['params']['arguments']), 0);
                            RequestAction($this->RequireObjectID($request['params']['arguments'] ?? [], 'variableID'), $request['params']['arguments']['value']);
                            $content = [
                                'success' => true
                            ];
                            break;

                        case 'current-time':
                            $timestamp = time();
                            $content = [
                                'timestamp' => $timestamp,
                                'formatted' => date('c', $timestamp)  // ISO 8601 format
                            ];
                            break;

                        case 'get-logged-data':
                            $variableID = $this->RequireObjectID($request['params']['arguments'] ?? [], 'variableID');
                            $from = $request['params']['arguments']['from'] ?? 0;
                            $to = $request['params']['arguments']['to'] ?? 0;
                        
                            // Get logged data using AC_GetLoggedValues
                            $loggedData = AC_GetLoggedValues(IPS_GetInstanceListByModuleID('{43192F0B-135B-4CE7-A0A7-1475603F3060}')[0], $variableID, $from, $to, 0);
                        
                            // Add formatted timestamp to each entry
                            foreach ($loggedData as &$entry) {
                                $entry['FormattedTime'] = date('c', $entry['TimeStamp']);
                            }
                        
                            $content = [
                                'data' => $loggedData
                            ];
                            break;

                        case 'get-status-log':
                            $types = $request['params']['arguments']['types'] ?? [0, 1, 2, 3, 4, 5, 6]; // All types if not specified
                            $messages = [];
                        
                            // Get messages for each requested type
                            foreach ($types as $type) {
                                $typeMessages = UC_GetLastLogMessages(IPS_GetInstanceListByModuleID('{B69010EA-96D5-46DF-B885-24821B8C8DBD}')[0], $type);
                                foreach ($typeMessages as &$message) {
                                    // Add Type and FormattedTime to each message
                                    $message['Type'] = $type;
                                    $message['FormattedTime'] = date('c', $message['TimeStamp']);
                                }
                                $messages = array_merge($messages, $typeMessages);
                            }
                        
                            // Sort messages by timestamp (newest first)
                            usort($messages, function($a, $b) {
                                return $b['TimeStamp'] <=> $a['TimeStamp'];
                            });
                        
                            $content = [
                                'data' => $messages
                            ];
                            break;

                        default:
                            $error = [
                                'code' => -32602,
                                'message' => 'Unknown tool: ' . $toolName
                            ];
                            break 2;
                    }
                } catch (Throwable $e) {
                    // Execution errors are reported to the model as tool result, so it can correct its input
                    $this->SendDebug('MCP Tool Error', $e->getMessage(), 0);
                    $result = [
                        'content' => [
                            [
                                'type' => 'text',
                                'text' => $e->getMessage()
                            ]
                        ],
                        'isError' => true
                    ];
                    break;
                } finally {
                    restore_error_handler();
                }
                $result = [
                    'content' => [
                        [
                            'type' => 'text',
                            'text' => json_encode($content, JSON_INVALID_UTF8_SUBSTITUTE)
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
                        'name' => 'demo-server',
                        'version' => '1.0.0'
                    ]
                ];
                break;

            case 'ping':
                $result = new stdClass();
                break;

            default:
                $error ??= [
                    'code' => -32601,
                    'message' => 'Method not found: ' . $method
                ];
                break;
        }

        if ($error !== null) {
            $this->SendDebug('MCP Error', json_encode($error), 0);

            return json_encode([
                'jsonrpc' => '2.0',
                'id' => $request['id'],
                'error' => $error
            ], JSON_INVALID_UTF8_SUBSTITUTE);
        }

        $response = json_encode([
            'result' => $result,
            'jsonrpc' => '2.0',
            'id' => $request['id']
        ], JSON_INVALID_UTF8_SUBSTITUTE);

        // E.g. NAN or INF cannot be encoded. Never answer with an empty body
        if ($response === false) {
            $response = json_encode([
                'jsonrpc' => '2.0',
                'id' => $request['id'],
                'error' => [
                    'code' => -32603,
                    'message' => 'Internal error: ' . json_last_error_msg()
                ]
            ]);
        }

        // Symcon halts a hook whose output exceeds ips.output_buffer (1 MiB unless raised, e.g. by get-snapshot) and sends a plain text notice instead,
        // so answer with an error that still fits. Keep a margin of 5 % below the limit
        $limit = intval(ini_get('ips.output_buffer')) ?: 1048576;
        $size = strlen($response);
        if ($size > $limit - intdiv($limit, 20)) {
            $this->LogMessage('MCP: Response of ' . $method . ' too large (' . $size . ' bytes), sent an error instead', KL_WARNING);
            $message = 'Response too large (' . $size . ' bytes, Symcon allows at most ' . $limit . ' bytes). Narrow the request, e.g. a shorter time range or a smaller limit.';
            $response = json_encode($method === 'tools/call' ? [
                'result' => [
                    'content' => [
                        [
                            'type' => 'text',
                            'text' => $message
                        ]
                    ],
                    'isError' => true
                ],
                'jsonrpc' => '2.0',
                'id' => $request['id']
            ] : [
                'jsonrpc' => '2.0',
                'id' => $request['id'],
                'error' => [
                    'code' => -32603,
                    'message' => 'Internal error: ' . $message
                ]
            ], JSON_INVALID_UTF8_SUBSTITUTE);
        }

        $this->SendDebug('MCP Result', $response, 0);

        return $response;
    }

    /**
    * Reject arguments that are not declared in the input schema, including keys of nested objects (e.g. lastUpdate.from)
    */
    private function RequireKnownArguments(mixed $arguments, array $schema, string $path): void {
        $properties = (array) ($schema['properties'] ?? []);
        $supported = $properties === [] ? 'This tool takes no parameters.' : 'Supported: ' . implode(', ', array_keys($properties)) . '.';
        if (!is_array($arguments) || ($arguments !== [] && array_is_list($arguments))) {
            throw new Exception('Parameter "' . ($path === '' ? 'arguments' : $path) . '" must be an object. ' . $supported);
        }
        foreach ($arguments as $key => $value) {
            $name = ($path === '' ? '' : $path . '.') . $key;
            if (!array_key_exists($key, $properties)) {
                throw new Exception('Unknown parameter "' . $name . '". ' . $supported);
            }
            if ($value !== null && isset($properties[$key]['properties'])) {
                $this->RequireKnownArguments($value, $properties[$key], $name);
            }
        }
    }

    /**
    * Read an object ID from the tool arguments. Symcon silently converts null, booleans and fractions into other IDs
    * (e.g. null becomes the root object 0), so only integers in the valid range are accepted
    */
    private function RequireObjectID(array $arguments, string $key): int {
        $id = $arguments[$key] ?? null;
        if (is_string($id) && preg_match('/^\d+\z/', $id)) {
            $id = intval($id);
        }
        // Check the range before casting, a float outside of the integer range cannot be converted
        if (is_float($id) && $id >= 0 && $id <= 60000 && floor($id) === $id) {
            $id = intval($id);
        }
        if (!is_int($id) || $id < 0 || $id > 60000) {
            throw new Exception('Parameter "' . $key . '" must be an integer object ID in the range 0..60000. Use find-objects or get-children to look up IDs.');
        }
        return $id;
    }
}