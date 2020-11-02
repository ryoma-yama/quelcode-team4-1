<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\Tax $tax
 */
?>
<nav class="large-3 medium-4 columns" id="actions-sidebar">
    <ul class="side-nav">
        <li class="heading"><?= __('Actions') ?></li>
        <li><?= $this->Form->postLink(
                __('Delete'),
                ['action' => 'delete', $tax->id],
                ['confirm' => __('Are you sure you want to delete # {0}?', $tax->id)]
            )
        ?></li>
        <li><?= $this->Html->link(__('List Taxes'), ['action' => 'index']) ?></li>
        <li><?= $this->Html->link(__('List Payments'), ['controller' => 'Payments', 'action' => 'index']) ?></li>
        <li><?= $this->Html->link(__('New Payment'), ['controller' => 'Payments', 'action' => 'add']) ?></li>
    </ul>
</nav>
<div class="taxes form large-9 medium-8 columns content">
    <?= $this->Form->create($tax) ?>
    <fieldset>
        <legend><?= __('Edit Tax') ?></legend>
        <?php
            echo $this->Form->control('tax_rate');
            echo $this->Form->control('start_date');
        ?>
    </fieldset>
    <?= $this->Form->button(__('Submit')) ?>
    <?= $this->Form->end() ?>
</div>
