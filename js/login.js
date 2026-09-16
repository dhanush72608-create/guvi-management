$(document).ready(function() {
    $('#loginForm').on('submit', function(e) {
        e.preventDefault();

        const formData = {
            email: $('#email').val(),
            password: $('#password').val()
        };

        $.ajax({
            url: 'php/login.php',
            type: 'POST',
            contentType: 'application/json',
            data: JSON.stringify(formData),
            success: function(response) {
                if(response.status === 'success') {
                    // Store token in browser localStorage (No PHP Sessions used)
                    localStorage.setItem('token', response.token);
                    localStorage.setItem('user_email', formData.email);
                    
                    $('#alert-box').html('<div class="alert alert-success">Login successful! Redirecting...</div>');
                    setTimeout(() => {
                        window.location.href = 'profile.html';
                    }, 1000);
                } else {
                    $('#alert-box').html(`<div class="alert alert-danger">${response.message}</div>`);
                }
            },
            error: function() {
                $('#alert-box').html('<div class="alert alert-danger">An error occurred during login.</div>');
            }
        });
    });
});