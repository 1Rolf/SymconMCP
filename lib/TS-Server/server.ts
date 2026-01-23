import { McpServer, ResourceTemplate } from '@modelcontextprotocol/sdk/server/mcp.js';
import { StreamableHTTPServerTransport } from '@modelcontextprotocol/sdk/server/streamableHttp.js';
import express from 'express';
import { z } from 'zod';

// Parse command line arguments for Symcon base URL and MCP Server port
const args = process.argv.slice(2);
const symconUrlArg = args.find(arg => arg.startsWith('--symcon-url='));
const symconUrlFromArgs = symconUrlArg ? symconUrlArg.split('=')[1] : null;
const portArg = args.find(arg => arg.startsWith('--port='));
const portFromArgs = portArg ? parseInt(portArg.split('=')[1]) : null;

// Configure Symcon base URL (priority: command line > environment variable > default)
const symconBaseURL = symconUrlFromArgs || 'http://127.0.0.1:3777';
const symconHookURL = `${symconBaseURL}/hook/mcp/`;

console.log('Starting TS MCP Server');
console.log('Symcon Hook URL:', symconHookURL);

// Configure MCP Server port (priority: command line > environment variable > default)
const mcpPort = portFromArgs || parseInt(process.env.PORT || '3000');
console.log('MCP Server will start on port:', mcpPort);

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

fetch(symconHookURL, {
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
    let responseJson: any = {};

    // Decode and display the response body
    try {
        const responseText = await response.text();
        
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

    for (const tool of (responseJson?.result?.tools || [])) {
        console.log('Registering tool:', tool.name);
        console.log(tool);
        
        // Convert JSON Schema to Zod schema for MCP SDK
        const convertJsonSchemaToZod = (schema: any) => {
            if (!schema || !schema.properties) {
                return {};
            }
            
            const zodSchema: any = {};
            const requiredFields = schema.required || [];
            
            for (const [key, prop] of Object.entries(schema.properties)) {
                const propSchema = prop as any;
                let zodType;
                
                if (propSchema.type === 'number') {
                    zodType = z.number();
                } else if (propSchema.type === 'boolean') {
                    zodType = z.boolean();
                } else if (propSchema.type === 'string') {
                    zodType = z.string();
                } else if (propSchema.type === 'array') {
                    if (propSchema.items?.type === 'number') {
                        zodType = z.array(z.number());
                    } else if (propSchema.items?.type === 'string') {
                        zodType = z.array(z.string());
                    } else {
                        zodType = z.array(z.any());
                    }
                } else {
                    zodType = z.any();
                }
                
                // Make the field optional if it's not in the required array
                if (!requiredFields.includes(key)) {
                    zodType = zodType.optional();
                }
                
                zodSchema[key] = zodType;
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
                const result = await fetch(symconHookURL, {
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
                console.log(`Response body for tools/call ${tool.name} (text):`, responseText);
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

    // Configure MCP Server port (priority: command line > environment variable > default)
    const port = mcpPort;
    app.listen(port, () => {
        console.log(`Demo MCP Server running on http://localhost:${port}/mcp`);
    }).on('error', error => {
        console.error('Server error:', error);
        process.exit(1);
    });
});