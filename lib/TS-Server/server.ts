import { McpServer, ResourceTemplate } from '@modelcontextprotocol/sdk/server/mcp.js';
import { StreamableHTTPServerTransport } from '@modelcontextprotocol/sdk/server/streamableHttp.js';
import express from 'express';
import { z } from 'zod';

const SYMCON_HOOK_URL = 'http://127.0.0.1:3777/hook/mcp/';

console.log('Starting TS MCP Server');

// Create an MCP server
const server = new McpServer({
    name: 'demo-server',
    version: '1.0.0'
});

const makeSymconRpcRequest = async (method: string, params: any[]) => {
    try {
        // Prepare JSON RPC request for Symcon
        const rpcRequest = {
            jsonrpc: '2.0',
            method: method,
            params: params,
            id: Math.floor(Math.random() * 1000000)
        };

        // Make the JSON RPC call to Symcon Server
        const response = await fetch('http://127.0.0.1:3777/api/', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(rpcRequest)
        });

        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }

        const rpcResponse = await response.json();

        if (rpcResponse.error) {
            const output = { 
                success: false,
                error: `Symcon RPC Error: ${rpcResponse.error.message || JSON.stringify(rpcResponse.error)}`
            };
            return {
                content: [{ type: 'text' as const, text: JSON.stringify(output) }],
                structuredContent: output
            };
        }

        const output = { success: true };
        return {
            content: [{ type: 'text' as const, text: `Successfully executed ${method}(${params})` }],
            structuredContent: output
        };

    } catch (error) {
        const output = { 
            success: false,
            error: `Failed to connect to Symcon Server: ${error instanceof Error ? error.message : String(error)}`
        };
        return {
            content: [{ type: 'text' as const, text: JSON.stringify(output) }],
            structuredContent: output
        };
    }
}

fetch(SYMCON_HOOK_URL, {
    method: 'POST',
    headers: {
        'Content-Type': 'application/json'
    },
    body: JSON.stringify({
        jsonrpc: '2.0',
        method: 'tools/list',
        params: {},
        id: Math.floor(Math.random() * 1000000)
    })
}).then(async (response) => {    
    let responseJson = {};

    // Decode and display the response body
    try {
        const responseText = await response.text();
        console.log('Response body for tools/list (text):', responseText);
        
        // Try to parse as JSON if possible
        try {
            responseJson = JSON.parse(responseText);
        } catch (jsonError) {
            console.error('Response is not valid JSON, showing as text only');
            process.exit(1);
        }
    } catch (bodyError) {
        console.error('Error reading response body:', bodyError);
        process.exit(1);
    }

    for (const tool of responseJson.result.tools) {
        console.log('Registering tool:', tool.name);
        console.log(tool);
        
        // Convert JSON Schema to Zod schema for MCP SDK
        const convertJsonSchemaToZod = (schema: any) => {
            if (!schema || !schema.properties) {
                return {};
            }
            
            const zodSchema: any = {};
            for (const [key, prop] of Object.entries(schema.properties)) {
                const propSchema = prop as any;
                if (propSchema.type === 'number') {
                    zodSchema[key] = z.number();
                } else if (propSchema.type === 'boolean') {
                    zodSchema[key] = z.boolean();
                } else if (propSchema.type === 'string') {
                    zodSchema[key] = z.string();
                } else if (propSchema.type === 'array') {
                    if (propSchema.items?.type === 'number') {
                        zodSchema[key] = z.array(z.number());
                    } else if (propSchema.items?.type === 'string') {
                        zodSchema[key] = z.array(z.string());
                    } else {
                        zodSchema[key] = z.array(z.any());
                    }
                } else {
                    zodSchema[key] = z.any();
                }
            }
            return zodSchema;
        };

        server.registerTool(
            tool.name,
            {
                title: tool.title,
                description: tool.description,
                inputSchema: convertJsonSchemaToZod(tool.inputSchema),
                outputSchema: convertJsonSchemaToZod(tool.outputSchema)
            },
            async (args) => {
                const result = await fetch(SYMCON_HOOK_URL, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        jsonrpc: '2.0',
                        method: 'tools/call',
                        params: { name: tool.name, arguments: args },
                        id: Math.floor(Math.random() * 1000000)
                    })
                })
                const responseText = await result.text();
                return JSON.parse(responseText).result;
            }
        );
    }

    // Set up Express and HTTP transport
    const app = express();
    app.use(express.json());

    app.post('/mcp', async (req, res) => {
        console.log('Received MCP Request');
        console.log(req.body);
        // Create a new transport for each request to prevent request ID collisions
        const transport = new StreamableHTTPServerTransport({
            sessionIdGenerator: undefined,
            enableJsonResponse: true
        });

        res.on('close', () => {
            transport.close();
        });

        await server.connect(transport);
        await transport.handleRequest(req, res, req.body);
    });

    const port = parseInt(process.env.PORT || '3000');
    app.listen(port, () => {
        console.log(`Demo MCP Server running on http://localhost:${port}/mcp`);
    }).on('error', error => {
        console.error('Server error:', error);
        process.exit(1);
    });
});