#!/bin/bash

# Task Manager API - Log Viewer
# Quick script to view different types of logs

echo " Task Manager API - Log Viewer"
echo "================================"

# Check if logs directory exists
if [ ! -d "logs" ]; then
    echo " No logs directory found. Make sure Docker containers are running."
    exit 1
fi

case "${1:-menu}" in
    "app"|"application")
        echo " Application Logs (press Ctrl+C to exit):"
        echo "-------------------------------------------"
        if [ -f "logs/app.log" ]; then
            tail -f logs/app.log | jq '.'
        else
            echo "No application logs found."
        fi
        ;;
    
    "security")
        echo " Security Logs (press Ctrl+C to exit):"
        echo "---------------------------------------"
        if [ -f "logs/security.log" ]; then
            tail -f logs/security.log | jq '.'
        else
            echo "No security logs found."
        fi
        ;;
    
    "error")
        echo " Error Logs (press Ctrl+C to exit):"
        echo "------------------------------------"
        if [ -f "logs/error.log" ]; then
            tail -f logs/error.log | jq '.'
        else
            echo "No error logs found."
        fi
        ;;
    
    "all")
        echo " All Logs (press Ctrl+C to exit):"
        echo "----------------------------------"
        tail -f logs/*.log 2>/dev/null | jq '.' 2>/dev/null || tail -f logs/*.log
        ;;
    
    "list"|"ls")
        echo " Available Log Files:"
        echo "---------------------"
        ls -la logs/ 2>/dev/null || echo "No logs directory found."
        ;;
    
    "recent")
        echo " Recent Log Entries (last 20):"
        echo "-------------------------------"
        if [ -f "logs/app.log" ]; then
            tail -20 logs/app.log | jq '.'
        else
            echo "No application logs found."
        fi
        ;;
    
    *)
        echo ""
        echo "Usage: $0 [option]"
        echo ""
        echo "Options:"
        echo "  app, application  - View live application logs"
        echo "  security         - View live security logs"
        echo "  error           - View live error logs"
        echo "  all             - View all logs combined"
        echo "  list, ls        - List available log files"
        echo "  recent          - Show recent log entries"
        echo ""
        echo "Examples:"
        echo "  $0 app          - Follow application logs"
        echo "  $0 security     - Follow security logs"
        echo "  $0 recent       - Show last 20 entries"
        echo ""
        echo " Log files are now stored in: ./logs/"
        echo ""
        ;;
esac