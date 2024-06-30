<div class="modal fade modal-shortcut modal-slide" tabindex="-1" role="dialog" aria-labelledby="defaultModalLabel"
    aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="defaultModalLabel">Shortcuts</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body px-5">
                <?php
                $shortcut_items = [
                    ["locations.php", "fe-home", "My Locations"],
                    ["team.php", "fe-users", "My Team"],
                    ["users.php", "fe-user", "My Customers"],
                    ["create-user.php", "fe-user-plus", "Add Team"],
                    ["expense.php", "fe-dollar-sign", "Expenses"],
                    ["invoices.php", "fe-file-text", "Invoices"],
                    ["purchase.php", "fe-shopping-cart", "Purchase Sale"],
                    ["itemised.php", "fe-list", "Itemised Sale"]
                ];

                for ($i = 0; $i < count($shortcut_items); $i += 2) {
                    echo '<div class="row align-items-center mb-4">';
                    for ($j = $i; $j < min($i + 2, count($shortcut_items)); $j++) {
                        $item = $shortcut_items[$j];
                        echo '<div class="col-6 text-center">';
                        echo '<a href="' . $item[0] . '">';
                        echo '<div class="squircle bg-primary justify-content-center">';
                        echo '<i class="fe ' . $item[1] . ' fe-32 align-self-center text-white"></i>';
                        echo '</div>';
                        echo '<p>' . $item[2] . '</p>';
                        echo '</a>';
                        echo '</div>';
                    }
                    echo '</div>';
                }
                ?>
            </div>
        </div>
    </div>
</div>