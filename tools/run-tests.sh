#!/bin/bash
# Run all tests and checks

echo "========================================"
echo "Sun & Twilight Calendar - Full Test Run"
echo "========================================"
echo ""

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

FAILED=0

# Check if PHP is available
if ! command -v php &> /dev/null; then
    echo -e "${RED}❌ PHP is not installed${NC}"
    echo "Please install PHP to run tests"
    exit 1
fi

echo "1️⃣  Running PHPUnit Tests..."
echo "----------------------------------------"
# Run PHPUnit and capture output, checking exit code
if ./vendor/bin/phpunit --no-output 2>&1 > /dev/null; then
    echo -e "${GREEN}✅ PHPUnit tests passed${NC}"
else
    echo -e "${RED}❌ PHPUnit tests failed${NC}"
    echo "Run './vendor/bin/phpunit' for details"
    FAILED=1
fi

echo ""
echo "2️⃣  Running PHP-CS-Fixer..."
echo "----------------------------------------"
if command -v php-cs-fixer &> /dev/null; then
    if php-cs-fixer fix --dry-run --diff > /dev/null 2>&1; then
        echo -e "${GREEN}✅ Code style check passed${NC}"
    else
        echo -e "${YELLOW}⚠️  Code style warnings (non-critical)${NC}"
    fi
else
    echo -e "${YELLOW}⚠️  php-cs-fixer not found, skipping code style check${NC}"
fi

echo ""
echo "3️⃣  Checking PHP Syntax..."
echo "----------------------------------------"
FILES="src/calendar-generator.php sunrise-sunset-calendar.php src/strings.php"
SYNTAX_OK=1

for file in $FILES; do
    if php -l "$file" > /dev/null 2>&1; then
        echo -e "${GREEN}✅ ${file}${NC}"
    else
        echo -e "${RED}❌ ${file} has syntax errors${NC}"
        php -l "$file"
        SYNTAX_OK=0
        FAILED=1
    fi
done

if [ $SYNTAX_OK -eq 1 ]; then
    echo -e "${GREEN}All files have valid syntax${NC}"
fi

echo ""
echo "4️⃣  Test Calendar Generation..."
echo "----------------------------------------"
if php sunrise-sunset-calendar.php > test-output.ics 2>&1; then
    LINES=$(wc -l < test-output.ics)
    if [ $LINES -gt 100 ]; then
        echo -e "${GREEN}✅ Calendar generated successfully (${LINES} lines)${NC}"
        rm test-output.ics
    else
        echo -e "${YELLOW}⚠️  Calendar generated but seems too short (${LINES} lines)${NC}"
        echo "First 10 lines:"
        head -10 test-output.ics
    fi
else
    echo -e "${RED}❌ Calendar generation failed${NC}"
    FAILED=1
fi

echo ""
echo "========================================"
if [ $FAILED -eq 0 ]; then
    echo -e "${GREEN}🎉 All checks passed!${NC}"
    echo "========================================"
    exit 0
else
    echo -e "${RED}❌ Some checks failed${NC}"
    echo "========================================"
    exit 1
fi
