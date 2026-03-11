const { createApp } = Vue;

createApp({
    mixins: [window.AdminAuthMixin],
    data() {
        return {
            images: [],
            loading: true,
            showUpload: false,
            uploadAlt: '',
            uploading: false,
            uploadError: ''
        };
    },

    mounted() {
        this.checkAuth();
        this.loadImages();
    },

    methods: {
        async loadImages() {
            try {
                const response = await fetch('api/gallery.php');
                const data = await response.json();
                if (data.success) {
                    this.images = data.images;
                }
            } catch (e) {
                console.error('Error loading gallery:', e);
            } finally {
                this.loading = false;
            }
        },

        async uploadImage() {
            const fileInput = this.$refs.fileInput;
            if (!fileInput.files[0]) return;
            this.uploading = true;
            this.uploadError = '';
            const formData = new FormData();
            formData.append('image', fileInput.files[0]);
            formData.append('alt_text', this.uploadAlt);
            try {
                const response = await fetch('api/gallery.php', {
                    method: 'POST',
                    body: formData
                });
                const data = await response.json();
                if (data.success) {
                    this.showUpload = false;
                    this.uploadAlt = '';
                    fileInput.value = '';
                    await this.loadImages();
                } else {
                    this.uploadError = data.error || 'Upload mislukt';
                }
            } catch (e) {
                this.uploadError = 'Er ging iets mis';
            } finally {
                this.uploading = false;
            }
        },

        async deleteImage(image) {
            if (!await showConfirm('Weet je zeker dat je deze foto wilt verwijderen?')) return;
            try {
                const response = await fetch('api/gallery.php', {
                    method: 'DELETE',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id: image.id })
                });
                const data = await response.json();
                if (data.success) {
                    await this.loadImages();
                }
            } catch (e) {
                console.error('Delete error:', e);
            }
        }
    }
}).mount('#galerij-app');
