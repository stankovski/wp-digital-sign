/**
 * Digital Signage Admin Scripts
 * 
 * Handles Gutenberg editor initialization and other admin functionality
 */

(function($) {
    'use strict';
    
    $(document).ready(function() {
        // Handle layout accordion toggle
        $('.digsign-layout-accordion-header').on('click', function() {
            $(this).parent().toggleClass('digsign-layout-accordion-open');
        });
        
        // Handle layout selection changes
        $('#digsign_layout_type').on('change', handleLayoutChange);
    });
    
    /**
     * Handle layout change
     */
    function handleLayoutChange() {
        const layout = $(this).val();
        
        // Update layout preview
        $('.layout-preview-svg').hide();
        $(`#layout-preview-${layout}`).show();
        
        // Show/hide editors based on layout selection
        const headerEditor = $('#digsign-header-editor');
        const rightPanelEditor = $('#digsign-right-panel-editor');
        
        if (layout === 'fullscreen') {
            headerEditor.hide();
            rightPanelEditor.hide();
        } else if (layout === 'header-panels') {
            headerEditor.show();
            rightPanelEditor.show();
        } else if (layout === 'two-panels') {
            headerEditor.hide();
            rightPanelEditor.show();
        }
    }
})(jQuery);
