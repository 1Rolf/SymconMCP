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
        $request = json_decode(file_get_contents('php://input'), true);
        $result = [];

        $this->SendDebug('MCP Input', json_encode($request), 0);
        $this->SendDebug('MCP Method', $request['method'], 0);

        switch ($request['method']) {
            case 'tools/list':
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
                                        'type' => 'number'
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
                                        'type' => 'number'
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
                                        'type' => 'number'
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
                                        'type' => 'object'
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
                            'description' => 'Find Symcon objects based on various criteria. The supported filters are type and name. Type is an array of possible object types (0: category, 1: instance, 2: variable, 3: script, 4: event, 5: media, 6: link). Name is a string that will be searched for in the object names (case insensitive, partial match). If multiple filters are provided, only objects matching all criteria will be returned. For each object, the full object info as returned by IPS_GetObject will be included in the output, containing ObjectID, ObjectName, ObjectType and more.',
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
                        ]
                    ]
                ];
                break;

            case 'tools/call':
                $content = [];
                switch ($request['params']['name']) {
                    case 'get-name':
                        $content = [
                            'name' => IPS_GetName($request['params']['arguments']['objectID'])
                        ];
                        break;

                    case 'get-object':
                        $content = [
                            'info' => IPS_GetObject($request['params']['arguments']['objectID'])
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
                        $result = [];

                        foreach(json_decode(IPS_GetSnapshot(), true)['objects'] as $index => $object) {
                            if (str_contains(strtolower($object['name']), $searchName) && in_array($object['type'], $types)) {
                                $result[] = IPS_GetObject(intval(substr($index, 2)));
                            }
                        }
                        $content = [
                            'objects' => $result
                        ];
                        break;

                    case 'switch-boolean':
                        $this->SendDebug('Switch Variable', json_encode($request['params']['arguments']), 0);
                        RequestAction($request['params']['arguments']['variableID'], $request['params']['arguments']['value']);
                        $content = [
                            'success' => true
                        ];
                        break;

                    default:
                        throw new Exception('Tool not found');
                }
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
                        'name' => 'demo-server',
                        'version' => '1.0.0'
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
            'id' => $request['id']
        ]);
    }
}