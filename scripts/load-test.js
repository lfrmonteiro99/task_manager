#!/usr/bin/env node

/**
 * Load Testing Script for Task Manager API
 * Tests realistic usage patterns with JWT authentication
 */

const axios = require('axios');
const { performance } = require('perf_hooks');

class LoadTester {
    constructor(config = {}) {
        this.baseUrl = config.baseUrl || 'http://localhost:8080';
        this.concurrentUsers = config.concurrentUsers || 50;
        this.testDuration = config.testDuration || 60000; // 1 minute
        this.rampUpTime = config.rampUpTime || 10000; // 10 seconds
        
        this.users = [];
        this.results = {
            requests: 0,
            responses: 0,
            errors: 0,
            responseTimes: [],
            authErrors: 0,
            rateLimitErrors: 0,
            serverErrors: 0
        };
    }

    // Create test user and get JWT token
    async createTestUser(userId) {
        try {
            const email = `loadtest_user_${userId}@example.com`;
            const password = 'LoadTest123!';
            
            // Try to register user
            try {
                await axios.post(`${this.baseUrl}/auth/register`, {
                    name: `Load Test User ${userId}`,
                    email: email,
                    password: password
                });
            } catch (e) {
                // User might already exist, ignore
            }
            
            // Login to get JWT token
            const response = await axios.post(`${this.baseUrl}/auth/login`, {
                email: email,
                password: password
            });
            
            return {
                userId: userId,
                token: response.data.access_token,
                userEmail: email
            };
        } catch (error) {
            console.error(`Failed to create test user ${userId}:`, error.message);
            return null;
        }
    }

    // Realistic API usage patterns
    async simulateUserActivity(user) {
        const activities = [
            () => this.listTasks(user),
            () => this.createTask(user),
            () => this.getTaskStatistics(user),
            () => this.markTaskDone(user),
            () => this.updateTask(user),
            () => this.deleteTask(user)
        ];

        const startTime = Date.now();
        const endTime = startTime + this.testDuration;
        
        while (Date.now() < endTime) {
            try {
                // Random activity with realistic distribution
                const activity = this.getWeightedRandomActivity(activities);
                const reqStart = performance.now();
                
                await activity();
                
                const responseTime = performance.now() - reqStart;
                this.results.responses++;
                this.results.responseTimes.push(responseTime);
                
                // Random delay between requests (0.5-3 seconds)
                await this.sleep(500 + Math.random() * 2500);
                
            } catch (error) {
                this.results.errors++;
                this.categorizeError(error);
            }
        }
    }

    getWeightedRandomActivity(activities) {
        // Realistic usage pattern weights
        const weights = [
            0.40, // listTasks - most common
            0.20, // createTask
            0.15, // getTaskStatistics
            0.10, // markTaskDone
            0.10, // updateTask
            0.05  // deleteTask - least common
        ];
        
        const random = Math.random();
        let sum = 0;
        
        for (let i = 0; i < weights.length; i++) {
            sum += weights[i];
            if (random <= sum) {
                return activities[i];
            }
        }
        
        return activities[0]; // fallback
    }

    async listTasks(user) {
        this.results.requests++;
        const response = await axios.get(`${this.baseUrl}/task/list`, {
            headers: { Authorization: `Bearer ${user.token}` }
        });
        return response.data;
    }

    async createTask(user) {
        this.results.requests++;
        const taskData = {
            title: `Load Test Task ${Date.now()}`,
            description: `Created by ${user.userEmail} during load test`,
            due_date: new Date(Date.now() + 7 * 24 * 60 * 60 * 1000).toISOString().slice(0, 19).replace('T', ' ')
        };
        
        const response = await axios.post(`${this.baseUrl}/task/create`, taskData, {
            headers: { Authorization: `Bearer ${user.token}` }
        });
        return response.data;
    }

    async getTaskStatistics(user) {
        this.results.requests++;
        const response = await axios.get(`${this.baseUrl}/task/statistics`, {
            headers: { Authorization: `Bearer ${user.token}` }
        });
        return response.data;
    }

    async markTaskDone(user) {
        // First get user's tasks
        const tasks = await this.listTasks(user);
        if (tasks.tasks && tasks.tasks.length > 0) {
            const randomTask = tasks.tasks[Math.floor(Math.random() * tasks.tasks.length)];
            if (!randomTask.done) {
                this.results.requests++;
                const response = await axios.post(`${this.baseUrl}/task/${randomTask.id}/done`, {}, {
                    headers: { Authorization: `Bearer ${user.token}` }
                });
                return response.data;
            }
        }
    }

    async updateTask(user) {
        const tasks = await this.listTasks(user);
        if (tasks.tasks && tasks.tasks.length > 0) {
            const randomTask = tasks.tasks[Math.floor(Math.random() * tasks.tasks.length)];
            this.results.requests++;
            
            const updateData = {
                title: `Updated ${randomTask.title}`,
                description: `Updated during load test at ${new Date().toISOString()}`,
                due_date: randomTask.due_date
            };
            
            const response = await axios.put(`${this.baseUrl}/task/${randomTask.id}`, updateData, {
                headers: { Authorization: `Bearer ${user.token}` }
            });
            return response.data;
        }
    }

    async deleteTask(user) {
        const tasks = await this.listTasks(user);
        if (tasks.tasks && tasks.tasks.length > 0) {
            const randomTask = tasks.tasks[Math.floor(Math.random() * tasks.tasks.length)];
            this.results.requests++;
            
            const response = await axios.delete(`${this.baseUrl}/task/${randomTask.id}`, {
                headers: { Authorization: `Bearer ${user.token}` }
            });
            return response.data;
        }
    }

    categorizeError(error) {
        if (error.response) {
            const status = error.response.status;
            if (status === 401 || status === 403) {
                this.results.authErrors++;
            } else if (status === 429) {
                this.results.rateLimitErrors++;
            } else if (status >= 500) {
                this.results.serverErrors++;
            }
        }
    }

    async sleep(ms) {
        return new Promise(resolve => setTimeout(resolve, ms));
    }

    async run() {
        console.log(' Starting Task Manager API Load Test');
        console.log(` Configuration:`);
        console.log(`   - Concurrent Users: ${this.concurrentUsers}`);
        console.log(`   - Test Duration: ${this.testDuration / 1000}s`);
        console.log(`   - Ramp-up Time: ${this.rampUpTime / 1000}s`);
        console.log(`   - Target API: ${this.baseUrl}`);
        console.log('');

        // Create test users
        console.log('👥 Creating test users...');
        const userPromises = [];
        for (let i = 1; i <= this.concurrentUsers; i++) {
            userPromises.push(this.createTestUser(i));
        }
        
        this.users = (await Promise.all(userPromises)).filter(user => user !== null);
        console.log(` Created ${this.users.length} test users`);
        
        if (this.users.length === 0) {
            console.error(' No test users created. Exiting.');
            return;
        }

        // Start load test with ramp-up
        console.log('🏃 Starting user simulation...');
        const startTime = Date.now();
        const userPromises2 = [];

        for (let i = 0; i < this.users.length; i++) {
            const delay = (i / this.users.length) * this.rampUpTime;
            userPromises2.push(
                this.sleep(delay).then(() => this.simulateUserActivity(this.users[i]))
            );
        }

        // Wait for all users to complete
        await Promise.all(userPromises2);
        
        const totalTime = Date.now() - startTime;
        this.generateReport(totalTime);
    }

    generateReport(totalTime) {
        console.log('\n Load Test Results');
        console.log('==================');
        
        const avgResponseTime = this.results.responseTimes.length > 0 
            ? this.results.responseTimes.reduce((a, b) => a + b, 0) / this.results.responseTimes.length
            : 0;
            
        const p95ResponseTime = this.results.responseTimes.length > 0
            ? this.results.responseTimes.sort((a, b) => a - b)[Math.floor(this.results.responseTimes.length * 0.95)]
            : 0;
            
        const requestsPerSecond = this.results.requests / (totalTime / 1000);
        const successRate = this.results.responses / this.results.requests * 100;
        
        console.log(` Performance Metrics:`);
        console.log(`   Total Requests: ${this.results.requests}`);
        console.log(`   Successful Responses: ${this.results.responses}`);
        console.log(`   Errors: ${this.results.errors}`);
        console.log(`   Success Rate: ${successRate.toFixed(2)}%`);
        console.log(`   Requests/Second: ${requestsPerSecond.toFixed(2)}`);
        console.log(`   Average Response Time: ${avgResponseTime.toFixed(2)}ms`);
        console.log(`   95th Percentile Response Time: ${p95ResponseTime.toFixed(2)}ms`);
        
        console.log(`\n Error Breakdown:`);
        console.log(`   Authentication Errors (401/403): ${this.results.authErrors}`);
        console.log(`   Rate Limit Errors (429): ${this.results.rateLimitErrors}`);
        console.log(`   Server Errors (5xx): ${this.results.serverErrors}`);
        console.log(`   Other Errors: ${this.results.errors - this.results.authErrors - this.results.rateLimitErrors - this.results.serverErrors}`);
        
        console.log(`\n⏱️  Test Summary:`);
        console.log(`   Total Test Time: ${(totalTime / 1000).toFixed(2)}s`);
        console.log(`   Active Users: ${this.users.length}`);
        
        // Performance thresholds
        console.log(`\n Performance Assessment:`);
        if (avgResponseTime < 200) {
            console.log(`    Response Time: Excellent (< 200ms)`);
        } else if (avgResponseTime < 500) {
            console.log(`     Response Time: Good (< 500ms)`);
        } else {
            console.log(`    Response Time: Needs Improvement (> 500ms)`);
        }
        
        if (successRate > 99) {
            console.log(`    Success Rate: Excellent (> 99%)`);
        } else if (successRate > 95) {
            console.log(`     Success Rate: Good (> 95%)`);
        } else {
            console.log(`    Success Rate: Needs Improvement (< 95%)`);
        }
        
        if (requestsPerSecond > 100) {
            console.log(`    Throughput: Excellent (> 100 req/s)`);
        } else if (requestsPerSecond > 50) {
            console.log(`     Throughput: Good (> 50 req/s)`);
        } else {
            console.log(`    Throughput: Needs Improvement (< 50 req/s)`);
        }
    }
}

// CLI usage
if (require.main === module) {
    const config = {
        baseUrl: process.env.API_BASE_URL || 'http://localhost:8080',
        concurrentUsers: parseInt(process.env.CONCURRENT_USERS) || 20,
        testDuration: parseInt(process.env.TEST_DURATION) || 30000, // 30 seconds
        rampUpTime: parseInt(process.env.RAMP_UP_TIME) || 5000 // 5 seconds
    };
    
    const tester = new LoadTester(config);
    tester.run().catch(console.error);
}

module.exports = LoadTester;