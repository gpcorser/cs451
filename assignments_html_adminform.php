<?php
// Admin create/update form (HTML partial)
// NOTE: Do not nest forms. The per-file delete buttons are rendered
// outside the main create/update form to avoid browsers submitting the wrong form.
?>

<?php if ($isAdmin): ?>
    <fieldset class="form-box-peach mb-4">
        <legend class="form-box-legend">
            <?php echo htmlspecialchars($formTitle); ?>
        </legend>

        <form method="post" enctype="multipart/form-data"
              action="assignments.php<?php echo ($formMode === 'update' ? '?edit=' . (int)$valId : ''); ?>">

            <input type="hidden" name="action" value="<?php echo $formMode; ?>">
            <input type="hidden" name="id" value="<?php echo (int)$valId; ?>">

            <div class="row g-2 align-items-start">
                <div class="col-md-3">
                    <label class="form-label mb-1">Name</label>
                    <input type="text"
                           name="name"
                           class="form-control form-control-sm"
                           value="<?php echo htmlspecialchars($valName); ?>"
                           required>
                </div>

                <div class="col-md-3">
                    <label class="form-label mb-1">Description</label>
                    <textarea name="description"
                              class="form-control form-control-sm"
                              rows="2"
                              required><?php echo htmlspecialchars($valDescription); ?></textarea>
                </div>

                <div class="col-md-1">
                    <label class="form-label mb-1">Team Size</label>
                    <input type="number"
                           name="team_size"
                           min="3" max="4"
                           class="form-control form-control-sm"
                           value="<?php echo htmlspecialchars($valTeamSize); ?>"
                           <?php echo ($editingHasTeams ? 'readonly' : ''); ?>
                           required>
                    <?php if ($editingHasTeams): ?>
                        <div class="form-text small">Team size locked once teams exist.</div>
                    <?php endif; ?>
                </div>

                <div class="col-md-2">
                    <label class="form-label mb-1">Assigned</label>
                    <input type="date"
                           name="date_assigned"
                           class="form-control form-control-sm"
                           value="<?php echo htmlspecialchars($valDateAssigned); ?>"
                           required>
                </div>

                <div class="col-md-2">
                    <label class="form-label mb-1">Due</label>
                    <input type="date"
                           name="date_due"
                           class="form-control form-control-sm"
                           value="<?php echo htmlspecialchars($valDateDue); ?>"
                           required>
                </div>

                <div class="col-md-1 d-flex align-items-end">
                    <div class="w-100">
                        <button type="submit" class="btn btn-modern btn-sm w-100 mb-1">
                            <?php echo ($formMode === 'update' ? 'Update' : 'Add'); ?>
                        </button>
                        <?php if ($formMode === 'update'): ?>
                            <a href="assignments.php" class="btn btn-outline-modern btn-sm w-100">Cancel</a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="mt-3">
                <label class="form-label mb-1">Instruction PDFs (admin only, max 3, 2MB each)</label>
                <input type="file"
                       name="assignment_pdfs[]"
                       accept=".pdf"
                       multiple
                       class="form-control form-control-sm"
                       style="max-width:520px;">
                <div class="form-text small">Students will see these PDFs when they open the Teams view for the assignment.</div>
            </div>

        </form>

        <?php if ($formMode === 'update'): ?>
            <?php $editFiles = $assignmentFilesByAssignment[(int)$valId] ?? []; ?>
            <?php if (!empty($editFiles)): ?>
                <div class="mt-2">
                    <div class="text-muted small mb-1">Existing instruction PDFs:</div>
                    <ul class="mb-0">
                        <?php foreach ($editFiles as $f): ?>
                            <li>
                                <a href="download.php?type=assignment&id=<?php echo (int)$f['id']; ?>">
                                    <?php echo htmlspecialchars($f['original_name']); ?>
                                </a>
                                <form method="post"
                                      action="assignments.php?edit=<?php echo (int)$valId; ?>"
                                      class="d-inline"
                                      onsubmit="return confirm('Delete this PDF?');">
                                    <input type="hidden" name="action" value="delete_uploaded_file">
                                    <input type="hidden" name="file_type" value="assignment">
                                    <input type="hidden" name="file_id" value="<?php echo (int)$f['id']; ?>">
                                    <button type="submit" class="btn btn-outline-modern btn-sm">Delete</button>
                                </form>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </fieldset>
<?php endif; ?>
