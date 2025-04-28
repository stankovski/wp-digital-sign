// Async load images and run carousel
(function() {
    var carousel = document.getElementById('digsign-carousel');
    var loading = document.getElementById('digsign-loading');
    var slideEls = [];
    var idx = 0;
    var carouselInterval = null;
    
    // Config variables will be initialized by inline script
    // digsignConfig is declared in the inline script

    function startCarousel() {
        if (carouselInterval) clearInterval(carouselInterval);
        if (slideEls.length < 2) return;
        carouselInterval = setInterval(function() {
            slideEls[idx].classList.remove('active');
            idx = (idx + 1) % slideEls.length;
            slideEls[idx].classList.add('active');
        }, digsignConfig.slideDelay);
    }

    function renderSlides(data) {
        // Update intervals if provided in response
        if (data.settings) {
            if (data.settings.refresh_interval) {
                digsignConfig.refreshInterval = Math.max(1, data.settings.refresh_interval) * 1000;
            }
            if (data.settings.slide_delay) {
                digsignConfig.slideDelay = Math.max(1, data.settings.slide_delay) * 1000;
            }
            if (data.settings.hasOwnProperty('enable_qrcodes')) {
                digsignConfig.enableQrCodes = data.settings.enable_qrcodes;
            }
        }
        
        // Get slides from response
        var slides = data.slides || [];
        
        // Remove old slides
        slideEls.forEach(function(slide) { slide.remove(); });
        slideEls = [];
        if (!slides || !slides.length) {
            if (!loading) {
                loading = document.createElement('p');
                loading.id = 'digsign-loading';
                carousel.appendChild(loading);
            }
            loading.textContent = digsignConfig.i18n.noContent;
            return;
        }
        if (loading) loading.remove();
        
        slides.forEach(function(slide, i) {
            var slideEl = document.createElement('div');
            slideEl.classList.add('slide');
            
            if (slide.type === 'image') {
                var img = document.createElement('img');
                img.src = slide.content;
                img.alt = slide.post_title || 'Gallery Image';
                slideEl.appendChild(img);
            } else if (slide.type === 'html') {
                var contentDiv = document.createElement('div');
                contentDiv.classList.add('html-content');
                if (slide.title) {
                    var title = document.createElement('h2');
                    title.textContent = slide.title;
                    contentDiv.appendChild(title);
                }
                var contentContainer = document.createElement('div');
                contentContainer.innerHTML = slide.content;
                contentDiv.appendChild(contentContainer);
                slideEl.appendChild(contentDiv);
            }
            
            // Add QR code overlay only if URL is provided
            if (slide.qrcode) {
                var qrDiv = document.createElement('div');
                qrDiv.classList.add('qrcode-overlay');
                var qrImg = document.createElement('img');
                qrImg.src = slide.qrcode;
                qrImg.alt = 'Scan for more information';
                qrDiv.appendChild(qrImg);
                // Set visibility based on current enableQrCodes setting
                qrDiv.style.display = digsignConfig.enableQrCodes ? 'block' : 'none';
                slideEl.appendChild(qrDiv);
            }
            
            carousel.appendChild(slideEl);
            slideEls.push(slideEl);
        });
        
        // Reset index if out of bounds
        if (idx >= slideEls.length) idx = 0;
        slideEls.forEach(function(slide, i) {
            slide.className = 'slide' + (i === idx ? ' active' : '');
        });
        startCarousel();
    }

    function fetchContent() {
        fetch(digsignConfig.ajaxUrl)
            .then(function(res) { return res.json(); })
            .then(function(data) {
                renderSlides(data);
            })
            .catch(function() {
                if (loading) loading.textContent = digsignConfig.i18n.failedToLoad;
            });
    }

    // When DOM is loaded
    document.addEventListener('DOMContentLoaded', function() {
        fetchContent();
        setInterval(fetchContent, digsignConfig.refreshInterval);
    });
})();
