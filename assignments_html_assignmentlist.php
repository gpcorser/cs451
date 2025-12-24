<?php
// Assignment list (HTML partial)
// Expects: $assignments, $isAdmin
?>

<h2 class="status-section-title">Assignment List</h2>

<?php if (empty($assignments)): ?>
    <p>No assignments have been created yet.</p>
<?php else: ?>
    <div class="table-responsive">
        <table class="table table-sm table-striped align-middle status-table">
            <thead>
                <tr>
                    <th style="width: 40%;">Assignment</th>
                    <th class="text-nowrap" style="width: 15%;">Due</th>
                    <th style="width: 45%;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($assignments as $a): ?>
                    <?php $aid = (int)$a['id']; ?>
                    <tr>
                        <td>
                            <strong><?php echo htmlspecialchars($a['name']); ?></strong><br>
                            <small class="text-muted"><?php echo nl2br(htmlspecialchars($a['description'])); ?></small>
                        </td>

                        <td class="text-nowrap"><?php echo htmlspecialchars($a['date_due']); ?></td>

                        <td>
                            <div class="d-flex flex-wrap gap-1 mb-1">
                                <a href="reviews.php?assignment_id=<?php echo $aid; ?>" class="btn btn-modern btn-sm">Evals</a>
                                <a href="assignments.php?show_teams=<?php echo $aid; ?>" class="btn btn-modern btn-sm">Teams</a>

                                <?php if ($isAdmin): ?>
                                    <a href="assignments.php?edit=<?php echo $aid; ?>" class="btn btn-outline-modern btn-sm">Edit</a>
                                    <form method="post" action="assignments.php" onsubmit="return confirm('Delete this assignment?');">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?php echo $aid; ?>">
                                        <button type="submit" class="btn btn-outline-modern btn-sm">Delete</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
