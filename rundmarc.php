<?php
// Execute the dmarc.py script
$output = shell_exec('python3 dmarc.py');

// Output the result
echo "<pre>$output</pre>";
?>
