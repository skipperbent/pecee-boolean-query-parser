# pecee/boolean-query-parser

Convert a boolean search query into a query that is compatible with a fulltext search.

### Notes

This library is a maintained fork of the original project "BooleanSearchParser" created by DuncanOglethat, [available here](https://github.com/DuncanOgle/BooleanSearchParser).

The goal of this project is to iron out bugs, optimise the code, add new features and make it compatible with future versions of PHP.

## Requirements

- PHP 7.1 or higher

### Installation

```
composer require pecee/boolean-query-parser
```

### Parsing a query

```php
$parser = new \Pecee\BooleanQueryParser\BooleanQueryParser();

$formattedQuery = $parser->parse('ict OR (technology AND bob)');
```

**Output**

```
ict (+technology +bob)
```

### Order

Order and brackets are important, more often than not OR logic takes priority

`sales OR finance AND manager` will become `sales finance +manager` and not `sales +finance +manager`

## Examples

|Input|Output|
|-----|------|
|`ict` |   `+ict`|
|`ict it` |   `+ict +it`|
|`ict OR it` |   `ict it`|
|`NOT ict` |   `-ict`|
|`it NOT ict` |   `+it -ict`|
|`web AND (ict OR it)` |   `+web +(ict it)`|
|`ict OR (it AND web)` |   `ict (+it +web)`|
|`ict NOT (ict AND it AND web)` |   `+ict -(+ict +it +web)`|
|`php OR (NOT web NOT embedded ict OR it)` |   `php (-web -embedded ict it)`|
|`(web OR embedded) (ict OR it)` |   `+(web embedded) +(ict it)`|
|`develop AND (web OR (ict AND php))` |   `+develop +(web (+ict +php))`|
|`"ict` |   `null `|
|`"ict OR it"` |   `+"ict OR it"`|

## Advanced examples
|Input|Output|
|-----|------|
`"business development" or "it sales" and (danish or dutch or italian or denmark or holland or netherlands or italy)` | `"business development" "it sales" +(danish dutch italian denmark holland netherlands italy)`
`(procurement or buying or purchasing) and (marine or sea) and (engineering or engineer)` | `+(procurement buying purchasing) +(marine sea) +(engineering engineer)`

## Licence

Licensed under the MIT licence.

### The MIT License (MIT)

Copyright (c) 2018 Simon Sessingø / pecee-boolean-query-parser

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
SOFTWARE.