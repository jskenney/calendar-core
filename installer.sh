#!/usr/bin/bash

git clone https://github.com/jskenney/calendar.git
rm -rf calendar/.git calendar/.gitmodules calendar/calendar-core
mv calendar XXX
mv XXX/* .
rmdir XXX
git clone --recursive https://github.com/jskenney/calendar-core.git

echo "Please Answer a few questions,"
echo "ensure none of your answers have quotes or special characters"
echo ""

read -p "Enter the Course Number (ex. SI460): " dcourse
read -p "Enter the Course Description (ex. Computer Graphics): " ddesc
read -p "Enter a default password (ex. mypassword): " dpass

sudo sed -i 's|SI123|$dcourse|g' calendar.php
sudo sed -i 's|Applied Something|$ddesc|g' calendar.php
sudo sed -i 's|mypassword|$dpass|g' calendar.php
sudo sed -i 's|this...|$RANDOM|g' calendar.php

