JSONselect-php
==============

Port of JSONselect to PHP, for more information check http://jsonselect.org/ 


This implementation is heavily based on https://github.com/lloyd/JSONSelect/blob/master/src/jsonselect.js

My Port is not compatible with the port here: https://github.com/observu/JSONselect-php

There are some critical differences in that I've done the following:
- Upgraded the code to PHP5
- Added protected/public access to members
- Code Cleanups
- The constructor now takes the document to use
- If you pass a string to the constructor, it does not check that it's a json file before attempting to decode it using json_decode
- If you pass an array to the constructor, it'll use it without any further checks
- Of course the last two points aren't perfect, I'm open for mods that validate the data before use
- The find method was added, it's a clone of "match" which I didnt think was "jQuery" enough
- The find/match methods take the selector, not the document as in the "observu" version