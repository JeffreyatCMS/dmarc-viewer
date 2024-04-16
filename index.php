<?php

//PULL ENVIRONMENT VARS FROM DOCKER ENV
// Define MySQL database connection settings
$MYSQL_HOST = getenv('MYSQL_HOST');
//Check to verify environment variable exists
if($MYSQL_HOST !== false){
    //The variable exists, use value
    echo "MYSQL_HOST found: " . $MYSQL_HOST;
} else {
    //The variable does not exist, use default value
    echo "The MYSQL_HOST was not found, use default value";
    $MYSQL_HOST = '';
}
$MYSQL_USER = getenv('MYSQL_USER');
//Check to verify environment variable exists
if($MYSQL_USER !== false){
    //The variable exists, use value
    echo "MYSQL_USER found: " . $MYSQL_USER;
} else {
    //The variable does not exist, use default value
    echo "The MYSQL_USER was not found, use default value";
    $MYSQL_USER = '';
}
$MYSQL_PASSWORD = getenv('MYSQL_PASSWORD');
//Check to verify environment variable exists
if($MYSQL_PASSWORD !== false){
    //The variable exists, use value
    echo "MYSQL_PASSWORD found: " . $MYSQL_PASSWORD;
} else {
    //The variable does not exist, use default value
    echo "The MYSQL_PASSWORD was not found, use default value";
    $MYSQL_PASSWORD = '';
}
$MYSQL_DATABASE = getenv('MYSQL_DATABASE');
//Check to verify environment variable exists
if($MYSQL_DATABASE !== false){
    //The variable exists, use value
    echo "MYSQL_DATABASE found: " . $MYSQL_DATABASE;
} else {
    //The variable does not exist, use default value
    echo "The MYSQL_DATABASE was not found, use default value";
    $MYSQL_DATABASE = '';
}

// Connect to MySQL database
$connection = new mysqli($MYSQL_HOST, $MYSQL_USER, $MYSQL_PASSWORD, $MYSQL_DATABASE);

// Check connection
if ($connection->connect_error) {
    die("Connection failed: " . $connection->connect_error);
}

// Fetch emails from the database
$email_query = "SELECT * FROM emails";
$email_result = $connection->query($email_query);

// Function to parse XML content and generate table dynamically
function parseAndDisplayXML($xml_content) {
    $xml = simplexml_load_string($xml_content);

    if ($xml !== false) {
        echo '<table class="table table-bordered">';
        parseXmlElement($xml);
        echo '</table>';
    } else {
        echo 'Invalid XML';
    }
}

// Function to recursively parse XML elements
function parseXmlElement($element) {
    foreach ($element->children() as $child) {
        if ($child->count() > 0) {
            // If the child has children, recursively parse them
            parseXmlElement($child);
        } else {
            // If the child is a leaf node (no children), display its data
            $name = $child->getName();
            $value = htmlspecialchars($child);

            echo '<tr>';
            echo '<th>' . $name . '</th>';
            echo '<td>';

            // Check if the element name is spf, dkim, or result
            if ($name == "spf" || $name == "dkim" || $name == "result") {
                // Check the content of the element and display a red or green dot accordingly
                if ($value == "fail") {
                    echo '<span style="color: red;">&#x25cf;</span>'; // Red dot
                } elseif ($value == "pass") {
                    echo '<span style="color: green;">&#x25cf;</span>'; // Green dot
                } else {
                    // If the value is neither "fail" nor "pass", display it as is
                    echo $value;
                }
            } else {
                // For other elements, display the value as is
                echo $value;
            }

            echo '</td>';
            echo '</tr>';
        }
    }
}


?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Emails</title>
    <!-- Bootstrap CSS CDN -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        /* Custom styles for the sticky top bar */
        .top-bar {
            width: 100%;
            height: 100px;
            position: fixed;
            top: 0;
            left: 0;
            background-color: #dc3545; /* Red */
            color: #fff; /* White */
            z-index: 1000; /* Ensure the top bar appears above other content */
            margin-left: 0px;
        }

        .top-bar h2 {
            margin: 0;
            padding: 0;
            text-align: center;
        }

        /* Custom styles for the page content */
        .container {
            margin-top: 120px; /* Adjusted margin to account for the sticky top bar */
            background-color: #fff; /* White */
            color: #000; /* Black */
            padding-left: 20px; /* Added padding to create space between sidebar and content */
        }

        /* Style for compact list */
        .email-list {
            list-style-type: none;
            padding: 0;
        }

        .email-list-item {
            border: 1px solid #ccc;
            margin-bottom: 10px;
            padding: 10px;
        }

        .attachment-xml {
            margin-top: 10px;
            padding: 10px;
            border: 1px solid #ccc;
            background-color: #f9f9f9;
        }

        .table th,
        .table td {
            padding: 8px;
            vertical-align: top;
        }

        .pagination {
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <!-- Sticky top bar -->
    <div class="top-bar row align-items-center justify-content-around">
        <div class="col-auto">
            <button class="btn btn-secondary" id="runDMARC">&#x21bb; Pull DMARC</button>
        </div>
        <div class="col-auto">
            <h2 class="text-center mb-0">DMARC Logs</h2>
        </div>
        <div class="col-auto">
            <form class="d-flex" method="GET" action="">
                <input class="form-control me-2" type="text" name="search" placeholder="Search...">
                <button class="btn btn-secondary" type="submit">Search</button>
            </form>
        </div>
    </div>

    <!-- Page content -->
    <div class="container">
        <h1>Emails</h1>
        <ul class="email-list">
            <?php
            // Pagination settings
            $records_per_page = 10;
            $page = isset($_GET['page']) ? $_GET['page'] : 1;
            $offset = ($page - 1) * $records_per_page;

            // Sorting settings
            $sort_by = isset($_GET['sort']) ? $_GET['sort'] : 'received_datetime';
            $sort_order = isset($_GET['order']) ? $_GET['order'] : 'DESC';

            // Search query
            $search_query = isset($_GET['search']) ? $_GET['search'] : '';

            // Construct SQL query
            $sql = "SELECT * FROM emails";
            if (!empty($search_query)) {
                $sql .= " WHERE subject LIKE '%$search_query%' OR sender_email LIKE '%$search_query%'";
            }
            $sql .= " ORDER BY $sort_by $sort_order LIMIT $offset, $records_per_page";

            // Execute SQL query
            $email_result = $connection->query($sql);

            // Fetch and display emails
            while ($row = $email_result->fetch_assoc()) {
                ?>
                <li class="email-list-item">
                    <p>Subject: <?php echo $row['subject']; ?></p>
                    <p>Sender Email: <?php echo $row['sender_email']; ?></p>
                    <p>Received Datetime: <?php echo $row['received_datetime']; ?></p>
                    <p>Attachment Names: <?php echo $row['attachment_names']; ?></p>
                    <!-- Load XML content dynamically -->
                    <?php foreach (explode(', ', $row['attachment_names']) as $attachment_name) { ?>
                        <div class="attachment-xml" style="display: none;">
                            <?php
                            $filename = pathinfo("attachments/{$attachment_name}", PATHINFO_FILENAME);
                            // Check if the filename already contains ".xml"
                            if (strpos($filename, '.xml') === false) {
                                // If not, append ".xml" to the filename
                                $filename .= '.xml';
                            }
                            $xml_content = file_get_contents("attachments/{$filename}");
                            parseAndDisplayXML($xml_content);
                            ?>
                        </div>
                    <?php } ?>
                </li>
                <?php
            }
            ?>
        </ul>

        <!-- Pagination links -->
        <?php
        $total_records_sql = "SELECT COUNT(*) AS total FROM emails";
        $total_records_result = $connection->query($total_records_sql);
        $total_records = $total_records_result->fetch_assoc()['total'];
        $total_pages = ceil($total_records / $records_per_page);

        if ($total_pages > 1) {
            ?>
            <div class="pagination justify-content-center">
                <?php
                // Previous page link
                if ($page > 1) {
                    echo '<a class="page-link" aria-label="Previous" href="?page=' . ($page - 1) . '&search=' . urlencode($search_query) . '"><span aria-hidden="true">&laquo;</span></a>';
                }
        
                // Page numbers
                for ($i = 1; $i <= $total_pages; $i++) {
                    echo '<a class="page-link" href="?page=' . $i . '&search=' . urlencode($search_query) . '">' . $i . '</a>';
                }
        
                // Next page link
                if ($page < $total_pages) {
                    echo '<a class="page-link" aria-label="Next" href="?page=' . ($page + 1) . '&search=' . urlencode($search_query) . '"><span aria-hidden="true">&raquo;</span></a>';
                }
                ?>
            </div>
            <?php
        }
        ?>
    </div>


    <!-- Bootstrap JS CDN -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    <!-- Include jQuery library -->
    <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.5.1/jquery.min.js"></script>

    <!-- JavaScript for handling the sticky behavior and runDMARC -->
    <script>
        $(document).ready(function () {
            var topBar = $(".top-bar");
            var stickyOffset = topBar.offset().top;

            $(window).scroll(function () {
                if ($(window).scrollTop() >= stickyOffset) {
                    topBar.addClass("sticky");
                } else {
                    topBar.removeClass("sticky");
                }
            });
        });

        // Handle the click event for #runDMARC
        $('#runDMARC').click(function () {
            $.ajax({
                url: 'rundmarc.php', // PHP script URL
                type: 'POST',
                success: function (response) {
                    // Handle success response
                    alert('DMARC script executed successfully!');
                },
                error: function (xhr, status, error) {
                    // Handle error
                    console.error(error);
                    alert('Error executing DMARC script');
                }
            });
        });

        // Function to expand/collapse email details
        $('.email-list-item').click(function () {
            $(this).find('.attachment-xml').slideToggle();
        });
    </script>
</body>
</html>

<?php
// Close database connection
$connection->close();
?>
