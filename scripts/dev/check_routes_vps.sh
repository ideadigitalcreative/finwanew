#!/bin/bash

echo "=== CHECKING ROUTES ON VPS ==="
echo ""

# Check if wizard route exists
echo "1. Checking /subscriptions/new route:"
php artisan route:list | grep "subscriptions/new"

echo ""
echo "2. Checking all subscription routes:"
php artisan route:list | grep subscriptions

echo ""
echo "3. Testing route resolution:"
php artisan route:list --name=subscriptions.wizard

echo ""
echo "=== DONE ==="
