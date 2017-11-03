# Calendar version 4 (core)
This is the core scripts within the calendar system, and is what most users would want to clone after downloading 
the initial calendar software.

# How to clone
Since the system uses other git repos as submodules, you will want to clone via:
```
git clone --recursive https://github.com/jskenney/calendar-core.git
```
See the calendar repo for additional information

# Updating files
Within the calendar-core directory, run the following command to update the calendar core
```
git submodule update --recursive --remote
git pull --recurse-submodules
```
