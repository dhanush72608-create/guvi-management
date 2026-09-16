$(document).ready(function() {
    $('#registerForm').on('submit', function(e) {
        e.preventDefault();

        const formData = {
            name: $('#name').val(),
            email: $('#email').val(),
            password: $('#password').val(),
            age: $('#age').val(),
            dob: $('#dob').val(),
            contact: $('#contact').val()
        };

        $.ajax({
            url: 'php/register.php',
            type: 'POST',
            contentType: 'application/json',
            data: JSON.stringify(formData),
            success: function(response) {
                if(response.status === 'success') {
                    $('#alert-box').html('<div class="alert alert-success">Registration successful! Redirecting to login...</div>');
                    setTimeout(() => {
                        window.location.href = 'login.html';
                    }, 1500);
                } else {
                    $('#alert-box').html(`<div class="alert alert-danger">${response.message}</div>`);
                }
            },
            error: function() {
                $('#alert-box').html('<div class="alert alert-danger">An error occurred. Please try again.</div>');
            }
        });
    });
});